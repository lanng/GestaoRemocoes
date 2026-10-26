<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Dfe\AssertCteDocumentsEligibleForMdfe;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Criação transacional do CIOT de um lote de CT-e: trava a linha do lote e
 * valida o guard de duplicado DENTRO da transação, serializando a corrida
 * entre as duas entradas (defeito D5 — antes o exists() rodava solto).
 */
class CreateCiotForBatch
{
    public function __construct(private readonly AssertCteDocumentsEligibleForMdfe $cteEligibility) {}

    /**
     * Cria o rascunho vinculado ao lote a partir dos dados do formulário
     * (snapshots de pagantes/veículos, frete em centavos e poda B119).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DomainException quando o lote já possui um CIOT ativo.
     */
    public function handle(CteEmissionBatch $batch, array $data): Ciot
    {
        return $this->create($batch, $data);
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<string, mixed>  $data
     */
    public function handleForDocuments(Collection $documents, array $data, ?CteEmissionBatch $batch = null): Ciot
    {
        return $this->createForDocuments($documents, $data, $batch, allowActiveCiotReplacement: false);
    }

    /**
     * Cria um CIOT de substituição para corrigir um MDF-e rejeitado. O CIOT
     * anterior permanece ativo até o cancelamento manual pelo usuário.
     *
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<string, mixed>  $data
     */
    public function handleReplacementForDocuments(Collection $documents, array $data, ?CteEmissionBatch $batch = null): Ciot
    {
        return $this->createForDocuments($documents, $data, $batch, allowActiveCiotReplacement: true);
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<string, mixed>  $data
     */
    private function createForDocuments(
        Collection $documents,
        array $data,
        ?CteEmissionBatch $batch,
        bool $allowActiveCiotReplacement,
    ): Ciot {
        if ($documents->isEmpty() || $documents->contains(fn (mixed $document): bool => ! $document instanceof CteDocument || ! $document->exists)) {
            throw new DomainException('Selecione CT-es internos para preparar o CIOT.');
        }

        if ($documents->pluck('id')->unique()->count() !== $documents->count()) {
            throw new DomainException('A seleção contém o mesmo CT-e mais de uma vez.');
        }

        if ($documents->contains(fn (CteDocument $document): bool => $document->status !== CteDocumentStatusEnum::AUTHORIZED
            || ! preg_match('/^\d{44}$/', (string) $document->access_key))) {
            throw new DomainException('Todos os CT-es precisam estar autorizados e possuir chave de acesso válida para preparar o CIOT.');
        }

        $this->cteEligibility->handle($documents);

        $lines = $documents
            ->map(fn (CteDocument $document): ?CiotLineEnum => CiotLineEnum::tryFrom((string) ($document->snapshot['line'] ?? CiotLineEnum::VehicleRemoval->value)))
            ->unique()
            ->values();

        if ($lines->count() !== 1 || $lines->first() === null) {
            throw new DomainException('A preparação do CIOT não pode misturar CT-es de modalidades diferentes.');
        }

        $line = $lines->first();

        if ($line === CiotLineEnum::TankAlcohol && $documents->count() !== 1) {
            throw new DomainException('A preparação de álcool aceita exatamente um CT-e.');
        }

        $this->assertSelectedVehiclesAreEligible($data['vehicle_ids'] ?? null, $line);

        $requestedLine = CiotLineEnum::tryFrom((string) ($data['line'] ?? ''));

        if ($requestedLine !== $line) {
            throw new DomainException('A linha informada para o CIOT deve corresponder à modalidade dos CT-es selecionados.');
        }

        return $this->create($batch, $data, $allowActiveCiotReplacement);
    }

    private function assertSelectedVehiclesAreEligible(mixed $vehicleIds, CiotLineEnum $line): void
    {
        if (! is_array($vehicleIds) || $vehicleIds === []) {
            throw new DomainException('Selecione ao menos um veículo ativo para a modalidade do CIOT.');
        }

        $ids = [];

        foreach ($vehicleIds as $vehicleId) {
            if (! is_int($vehicleId) && (! is_string($vehicleId) || ! ctype_digit($vehicleId))) {
                throw new DomainException('A seleção contém um veículo inválido.');
            }

            $ids[] = (int) $vehicleId;
        }

        $ids = array_values(array_unique($ids));
        $vehicles = CiotVehicle::query()->whereIn('id', $ids)->get();

        if (count($ids) !== count($vehicleIds)
            || $vehicles->count() !== count($ids)
            || $vehicles->contains(fn (CiotVehicle $vehicle): bool => ! $vehicle->servesLine($line->value))) {
            throw new DomainException('Selecione somente veículos ativos disponíveis para a modalidade do CIOT.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(?CteEmissionBatch $batch, array $data, bool $allowActiveCiotReplacement = false): Ciot
    {
        return DB::transaction(function () use ($batch, $data, $allowActiveCiotReplacement): Ciot {
            $lockedBatch = $batch === null
                ? null
                : CteEmissionBatch::query()
                    ->whereKey($batch->id)
                    ->lockForUpdate()
                    ->firstOrFail();

            if ($lockedBatch?->status === CteEmissionBatchStatusEnum::DRAFT) {
                throw new DomainException('O CIOT não pode ser preparado enquanto o lote de CT-e estiver em revisão.');
            }

            if ($lockedBatch !== null && ! $allowActiveCiotReplacement && Ciot::query()
                ->where('cte_emission_batch_id', $lockedBatch->id)
                ->whereNotIn('status', [CiotStatusEnum::CANCELED->value])
                ->exists()) {
                throw new DomainException('Este lote já possui um CIOT ativo');
            }

            $data['public_id'] = (string) Str::uuid();
            $data['cte_emission_batch_id'] = $lockedBatch?->id;
            $data = CiotResource::transformFormData($data);

            /** @var Ciot $ciot */
            $ciot = Ciot::create($data);

            activity()
                ->performedOn($ciot)
                ->log($lockedBatch === null
                    ? 'CIOT gerado para seleção independente de CT-es.'
                    : ($allowActiveCiotReplacement
                        ? "CIOT de substituição gerado pelo lote de CT-e #{$lockedBatch->id}."
                        : "CIOT gerado pelo lote de CT-e #{$lockedBatch->id}."));

            return $ciot;
        });
    }
}
