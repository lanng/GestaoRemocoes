<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDriver;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PrepareMdfeDocument
{
    public function __construct(
        private readonly CreateCiotForBatch $ciotCreator,
        private readonly EmitCiotDeclaration $ciotEmitter,
        private readonly ValidateMdfeDocumentSelection $selectionValidator,
        private readonly QueueDirectMdfeEmission $mdfeQueue,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?CteEmissionBatch $batch = null): PrepareMdfeDocumentResult
    {
        $documents = $this->selectedDocuments($data['cte_document_ids'] ?? null);

        if ($batch !== null && $documents->contains(
            fn (CteDocument $document): bool => (int) $document->cte_emission_batch_id !== (int) $batch->id,
        )) {
            throw new DomainException('O lote de contexto não corresponde a todos os CT-es selecionados.');
        }

        $line = $this->selectedLine($documents);
        $endorsements = $this->endorsements($data['endorsements'] ?? null);
        $routeUfs = $this->routeUfs($data['route_ufs'] ?? []);
        $driver = $this->driver($line, $data['driver_id'] ?? null);
        $homologationFixture = $this->homologationFixture($data);

        $ciotEmissionResult = null;
        $ciot = match ($data['ciot_mode'] ?? null) {
            'existing' => $this->existingCiot($documents, $data['ciot_id'] ?? null),
            'new' => $this->createAndEmitCiot($documents, $data['new_ciot'] ?? null, $batch, $ciotEmissionResult),
            default => throw new DomainException('Escolha um CIOT emitido ou crie e emita um novo CIOT.'),
        };

        if ($ciotEmissionResult !== null && ! $ciotEmissionResult->isIssued()) {
            return new PrepareMdfeDocumentResult(
                ciot: $ciotEmissionResult->ciot,
                mdfe: null,
                ciotEmissionResult: $ciotEmissionResult,
                message: $ciotEmissionResult->message,
            );
        }

        $this->selectionValidator->handle($documents, $ciot);

        try {
            $mdfe = $this->mdfeQueue->handleForDocuments(
                documents: $documents,
                ciot: $ciot,
                endorsements: $endorsements,
                routeUfs: $routeUfs,
                batch: $batch,
                driver: $driver,
                homologationFixture: $homologationFixture,
                paymentMethod: $data['payment_method'] ?? null,
                paymentBankCode: $data['payment_bank_code'] ?? null,
                paymentBankAgency: $data['payment_bank_agency'] ?? null,
            );
        } catch (DomainException $exception) {
            if ($ciotEmissionResult === null) {
                throw $exception;
            }

            return new PrepareMdfeDocumentResult(
                ciot: $ciot,
                mdfe: null,
                ciotEmissionResult: $ciotEmissionResult,
                message: $exception->getMessage(),
            );
        }

        return new PrepareMdfeDocumentResult(
            ciot: $ciot,
            mdfe: $mdfe,
            ciotEmissionResult: $ciotEmissionResult,
        );
    }

    /**
     * @return Collection<int, CteDocument>
     */
    public function eligibleCteDocuments(CiotLineEnum $line, string $search = '', ?int $batchId = null, int $limit = 50): Collection
    {
        $query = $this->eligibleCteDocumentQuery($line, $batchId);

        if (filled($search)) {
            $query->where(function (Builder $query) use ($search): void {
                $like = '%'.trim($search).'%';

                $query->where('cte_number', 'like', $like)
                    ->orWhere('series', 'like', $like)
                    ->orWhere('access_key', 'like', $like);
            });
        }

        return $query
            ->orderByDesc('authorized_at')
            ->orderByRaw('CAST(cte_number AS DECIMAL(20, 0)) DESC')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<int, CteDocument>
     */
    public function eligibleCteDocumentsByIds(CiotLineEnum $line, array $ids, ?int $batchId = null): Collection
    {
        $ids = collect($ids)
            ->filter(fn (mixed $id): bool => (is_int($id) && $id > 0)
                || (is_string($id) && ctype_digit($id) && (int) $id > 0))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $documentsById = $this->eligibleCteDocumentQuery($line, $batchId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids
            ->map(fn (int $id): ?CteDocument => $documentsById->get($id))
            ->filter()
            ->values();
    }

    public function hasEligibleCteDocumentsForBatch(int $batchId): bool
    {
        foreach (CiotLineEnum::cases() as $line) {
            if ($this->eligibleCteDocumentQuery($line, $batchId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return Builder<CteDocument> */
    private function eligibleCteDocumentQuery(CiotLineEnum $line, ?int $batchId = null): Builder
    {
        return CteDocument::query()
            ->eligibleForNewMdfe()
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->whereNotNull('access_key')
            ->where('snapshot->line', $line->value)
            ->when($batchId !== null, fn (Builder $query): Builder => $query->where('cte_emission_batch_id', $batchId))
            ->whereDoesntHave('mdfeDocuments', function (Builder $query): void {
                $query->where('status', '!=', CteDocumentStatusEnum::CANCELLED->value)
                    ->where(function (Builder $query): void {
                        $query->whereNull('fiscal_status')
                            ->orWhere('fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value);
                    })
                    ->where(function (Builder $query): void {
                        $query->whereNull('fiscal_status_code')
                            ->orWhere('fiscal_status_code', '!=', '101');
                    });
            });
    }

    /**
     * @return Collection<int, Ciot>
     */
    public function eligibleCiots(CiotLineEnum $line, ?int $batchId = null): Collection
    {
        return Ciot::query()
            ->where('line', $line->value)
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->when($batchId !== null, fn (Builder $query): Builder => $query->where('cte_emission_batch_id', $batchId))
            ->whereDoesntHave('mdfeDocuments', function (Builder $query): void {
                $query
                    ->whereNotIn('status', [
                        CteDocumentStatusEnum::REJECTED->value,
                        CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                        CteDocumentStatusEnum::CANCELLED->value,
                    ])
                    ->where(function (Builder $query): void {
                        $query->whereNull('fiscal_status_code')
                            ->orWhereNotIn('fiscal_status_code', ['101', '132']);
                    });
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reject(fn (Ciot $ciot): bool => $ciot->isSyntheticHomologation())
            ->values();
    }

    public function cteOptionLabel(CteDocument $document): string
    {
        $snapshot = (array) $document->snapshot;
        $line = CiotLineEnum::tryFrom((string) ($snapshot['line'] ?? ''));
        $remitter = (string) data_get($snapshot, 'rem.xNome', data_get($snapshot, 'rem.name', data_get($snapshot, 'company', '—')));
        $recipient = (string) data_get($snapshot, 'dest.xNome', data_get($snapshot, 'dest.name', '—'));
        $origin = $this->placeLabel((array) ($snapshot['origin'] ?? []), (string) ($snapshot['origin_city'] ?? ''));
        $destination = $this->placeLabel(
            (array) ($snapshot['delivery_party'] ?? $snapshot['destination'] ?? []),
            (string) ($snapshot['destination_city'] ?? ''),
        );
        $freight = number_format((float) ($snapshot['value'] ?? 0), 2, ',', '.');
        $emittedAt = $document->authorized_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—';
        $batch = $document->cte_emission_batch_id === null ? 'avulso' : "lote #{$document->cte_emission_batch_id}";

        return sprintf(
            'CT-e %s/%s · %s · %s · %s → %s · %s → %s · frete R$ %s · %s',
            $document->cte_number ?? '—',
            $document->series ?? '—',
            $emittedAt,
            $line?->label() ?? 'Modalidade não informada',
            $remitter,
            $recipient,
            $origin,
            $destination,
            $freight,
            $batch,
        );
    }

    public function ciotOptionLabel(Ciot $ciot): string
    {
        $line = $ciot->line instanceof CiotLineEnum ? $ciot->line : CiotLineEnum::tryFrom((string) $ciot->line);
        $syntheticLabel = $ciot->isSyntheticHomologation() ? ' (sintético de homologação)' : '';

        return sprintf(
            'CIOT %s%s · %s · %s · %s → %s · emitido em %s',
            $ciot->fullNumber() ?? '—',
            $syntheticLabel,
            $line?->label() ?? 'Modalidade não informada',
            $ciot->payer_name ?? 'Contratante não informado',
            $this->placeLabel((array) $ciot->origin, ''),
            $this->placeLabel((array) $ciot->destination, ''),
            $ciot->issued_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—',
        );
    }

    /**
     * @return Collection<int, CteDocument>
     */
    private function selectedDocuments(mixed $selectedIds): Collection
    {
        if (! is_array($selectedIds) || $selectedIds === []) {
            throw new DomainException('Selecione ao menos um CT-e autorizado.');
        }

        $ids = [];

        foreach ($selectedIds as $selectedId) {
            if (! is_int($selectedId) && (! is_string($selectedId) || ! ctype_digit($selectedId))) {
                throw new DomainException('A seleção contém um CT-e inválido.');
            }

            $ids[] = (int) $selectedId;
        }

        if (in_array(0, $ids, true) || count($ids) !== count(array_unique($ids))) {
            throw new DomainException('A seleção contém o mesmo CT-e mais de uma vez ou um identificador inválido.');
        }

        $documentsById = CteDocument::query()->whereIn('id', $ids)->get()->keyBy('id');

        if ($documentsById->count() !== count($ids)) {
            throw new DomainException('Um ou mais CT-es selecionados não foram encontrados.');
        }

        return collect($ids)
            ->map(fn (int $id): CteDocument => $documentsById->get($id))
            ->values();
    }

    /** @param Collection<int, CteDocument> $documents */
    private function selectedLine(Collection $documents): CiotLineEnum
    {
        $lines = $documents
            ->map(fn (CteDocument $document): ?CiotLineEnum => CiotLineEnum::tryFrom((string) ($document->snapshot['line'] ?? CiotLineEnum::VehicleRemoval->value)))
            ->unique()
            ->values();

        if ($lines->count() !== 1 || $lines->first() === null) {
            throw new DomainException('O MDF-e não pode misturar CT-es de modalidades diferentes.');
        }

        $line = $lines->first();

        if ($line === CiotLineEnum::TankAlcohol && $documents->count() !== 1) {
            throw new DomainException('O MDF-e de álcool aceita exatamente um CT-e.');
        }

        return $line;
    }

    /** @param array<string, mixed> $place */
    private function placeLabel(array $place, string $fallback): string
    {
        $name = (string) ($place['name'] ?? $place['xNome'] ?? $place['cidade'] ?? $place['xMun'] ?? $fallback);
        $state = (string) ($place['uf'] ?? $place['UF'] ?? '');

        return $state === '' ? ($name === '' ? '—' : $name) : trim($name).' / '.strtoupper($state);
    }

    /**
     * @return list<string>
     */
    private function endorsements(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new DomainException('Informe ao menos um número de averbação para o MDF-e.');
        }

        $endorsements = [];

        foreach ($rows as $row) {
            $number = is_array($row) ? ($row['number'] ?? null) : $row;

            if (! is_string($number) || trim($number) === '') {
                continue;
            }

            $number = trim($number);

            if (mb_strlen($number) > 40) {
                throw new DomainException('Número de averbação do MDF-e excede 40 caracteres.');
            }

            $endorsements[] = $number;
        }

        if ($endorsements === []) {
            throw new DomainException('Informe ao menos um número de averbação para o MDF-e.');
        }

        return array_values(array_unique($endorsements));
    }

    /** @param mixed $routeUfs @return list<string> */
    private function routeUfs(mixed $routeUfs): array
    {
        if (! is_array($routeUfs)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $uf): string => strtoupper(trim((string) $uf)), $routeUfs),
            fn (string $uf): bool => $uf !== '',
        ));
    }

    private function driver(CiotLineEnum $line, mixed $driverId): ?MdfeDriver
    {
        if ($line !== CiotLineEnum::TankAlcohol) {
            return null;
        }

        if (! is_int($driverId) && (! is_string($driverId) || ! ctype_digit($driverId))) {
            throw new DomainException('Selecione um motorista ativo para o MDF-e de álcool.');
        }

        $driver = MdfeDriver::query()
            ->where('line', $line->value)
            ->where('is_active', true)
            ->find((int) $driverId);

        if ($driver === null) {
            throw new DomainException('Selecione um motorista ativo para o MDF-e de álcool.');
        }

        return $driver;
    }

    /** @param array<string, mixed> $data @return array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null */
    private function homologationFixture(array $data): ?array
    {
        if (! ($data['homologation_fixture'] ?? false) || (string) config('dfe.tp_amb') !== '2') {
            return null;
        }

        return [
            'insurer_name' => (string) ($data['fixture_insurer_name'] ?? ''),
            'insurer_cnpj' => (string) ($data['fixture_insurer_cnpj'] ?? ''),
            'policy_number' => (string) ($data['fixture_policy_number'] ?? ''),
        ];
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     */
    private function existingCiot(Collection $documents, mixed $ciotId): Ciot
    {
        if (! is_int($ciotId) && (! is_string($ciotId) || ! ctype_digit($ciotId))) {
            throw new DomainException('Selecione um CIOT emitido e aberto.');
        }

        $ciot = Ciot::query()->find((int) $ciotId);

        if ($ciot === null) {
            throw new DomainException('O CIOT selecionado não foi encontrado.');
        }

        $this->selectionValidator->handle($documents, $ciot);

        return $ciot;
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     */
    private function createAndEmitCiot(
        Collection $documents,
        mixed $newCiotData,
        ?CteEmissionBatch $batch,
        ?CiotEmissionResult &$ciotEmissionResult,
    ): Ciot {
        if (! is_array($newCiotData)) {
            throw new DomainException('Preencha os dados para criar um novo CIOT.');
        }

        $ciot = $this->ciotCreator->handleForDocuments($documents, $newCiotData, $batch);
        $ciotEmissionResult = $this->ciotEmitter->emit($ciot);

        return $ciotEmissionResult->ciot;
    }
}
