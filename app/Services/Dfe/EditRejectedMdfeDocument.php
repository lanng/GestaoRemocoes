<?php

namespace App\Services\Dfe;

use App\Enums\CiotLineEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\BuildMdfeEmissionSnapshot;
use App\Services\Ciot\CiotEmissionResult;
use App\Services\Ciot\CreateCiotForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Ciot\ValidateMdfeDocumentSelection;
use DomainException;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EditRejectedMdfeDocument
{
    public function canEdit(MdfeDocument $document): bool
    {
        return $document->status === CteDocumentStatusEnum::REJECTED
            && $document->fiscal_status === FiscalDocumentStatusEnum::NOT_AUTHORIZED
            && $document->emitter === 'direct'
            && $document->execution_mode === 'live'
            && blank($document->protocol)
            && $document->authorized_at === null
            && $document->issued_at === null
            && blank($document->xml_path)
            && (int) $document->fiscal_status_code >= 200
            && ! in_array((string) $document->fiscal_status_code, ['204', '539'], true)
            && filled($document->response_xml)
            && filled($document->mdfe_number)
            && filled($document->series);
    }

    /** @return array<string, mixed> */
    public function formData(MdfeDocument $document): array
    {
        $snapshot = $document->snapshot;
        $driverCpf = $snapshot['vehicle']['drivers'][0]['cpf'] ?? null;

        return [
            'line' => $snapshot['line'] ?? $document->ciot?->line?->value,
            'cte_document_ids' => $document->cteDocuments()->orderByPivot('position')->pluck('cte_documents.id')->all(),
            'ciot_mode' => 'existing',
            'ciot_id' => $document->ciot_id,
            'driver_id' => MdfeDriver::query()->where('cpf', $driverCpf)->where('is_active', true)->value('id'),
            'endorsements' => array_map(fn (string $number): array => ['number' => $number], $snapshot['insurance']['endorsements'] ?? []),
            'route_ufs' => $snapshot['route_ufs'] ?? [],
            'homologation_fixture' => ($snapshot['insurance']['source'] ?? null) === 'homologation_fixture',
            'fixture_insurer_name' => $snapshot['insurance']['insurer_name'] ?? null,
            'fixture_insurer_cnpj' => $snapshot['insurance']['insurer_cnpj'] ?? null,
            'fixture_policy_number' => $snapshot['insurance']['policy_number'] ?? null,
            'new_ciot' => [],
        ];
    }

    /** @param array<string, mixed> $data */
    public function createAndEmitReplacementCiot(MdfeDocument $document, array $data): CiotEmissionResult
    {
        $this->assertConfirmedRejection($document);
        Validator::make($data, [
            'ciot_mode' => ['required', 'in:new'],
            'line' => ['required', 'in:vehicle_removal,tank_alcohol'],
            'cte_document_ids' => ['required', 'array', 'min:1'],
            'cte_document_ids.*' => ['required', 'integer', 'distinct'],
            'new_ciot' => ['required', 'array'],
        ])->validate();

        $selectedIds = collect($data['cte_document_ids'])->map(fn (int|string $id): int => (int) $id);
        $documents = CteDocument::query()->whereIn('id', $selectedIds)->orderBy('id')->get();
        if ($documents->count() !== $selectedIds->count()) {
            throw new DomainException('A seleção contém CT-es inexistentes ou duplicados.');
        }
        $documents = $selectedIds->map(fn (int $id): CteDocument => $documents->firstWhere('id', $id));
        $ciotData = $data['new_ciot'];
        $ciotData['status'] = 'draft';
        $ciotData['line'] = $data['line'];
        $ciot = app(CreateCiotForBatch::class)->handleReplacementForDocuments(
            $documents,
            $ciotData,
            $document->batch,
        );

        return app(EmitCiotDeclaration::class)->emit($ciot);
    }

    /** @param array<string, mixed> $data */
    public function save(MdfeDocument $document, array $data): MdfeDocument
    {
        return DB::transaction(function () use ($document, $data): MdfeDocument {
            $locked = MdfeDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertConfirmedRejection($locked);
            Validator::make($data, [
                'ciot_mode' => ['required', 'in:existing'],
                'ciot_id' => ['required', 'integer'],
                'line' => ['required', 'in:vehicle_removal,tank_alcohol'],
                'cte_document_ids' => ['required', 'array', 'min:1'],
                'cte_document_ids.*' => ['required', 'integer', 'distinct'],
                'endorsements' => ['required', 'array', 'min:1'],
                'endorsements.*.number' => ['required', 'string', 'max:40'],
                'route_ufs' => ['present', 'array'],
                'driver_id' => ['required_if:line,tank_alcohol', 'nullable', 'integer'],
            ])->validate();
            $selectedIds = collect($data['cte_document_ids'])->map(fn (int|string $id): int => (int) $id);
            $documents = CteDocument::query()->whereIn('id', $selectedIds)->orderBy('id')->lockForUpdate()->get();
            if ($documents->count() !== $selectedIds->count()) {
                throw new DomainException('A seleção contém CT-es inexistentes ou duplicados.');
            }
            $documents = $selectedIds->map(fn (int $id): CteDocument => $documents->firstWhere('id', $id));
            $ciot = Ciot::query()->lockForUpdate()->findOrFail($data['ciot_id']);
            if (! $ciot->isOpen()) {
                throw new DomainException('Selecione um CIOT emitido e aberto.');
            }
            $line = app(ValidateMdfeDocumentSelection::class)->handle($documents, $ciot);
            if ($line->value !== $data['line']) {
                throw new DomainException('A modalidade deve corresponder aos CT-es e ao CIOT selecionados.');
            }
            $driver = $line === CiotLineEnum::TankAlcohol
                ? MdfeDriver::query()->whereKey($data['driver_id'])->where('is_active', true)->where('line', $line->value)->first()
                : null;
            if ($line === CiotLineEnum::TankAlcohol && $driver === null) {
                throw new DomainException('Selecione um motorista ativo para o MDF-e de álcool.');
            }
            $endorsements = array_values(array_unique(array_map(fn (array $row): string => trim($row['number']), $data['endorsements'])));
            $fixture = ($data['homologation_fixture'] ?? false) ? [
                'insurer_name' => $data['fixture_insurer_name'] ?? '',
                'insurer_cnpj' => $data['fixture_insurer_cnpj'] ?? '',
                'policy_number' => $data['fixture_policy_number'] ?? '',
            ] : null;
            $sameSelection = (int) $locked->ciot_id === (int) $ciot->id
                && $locked->cteDocuments()->orderByPivot('position')->pluck('cte_documents.id')->map(fn ($id): int => (int) $id)->all() === $selectedIds->all();
            $snapshotBuilder = app(BuildMdfeEmissionSnapshot::class);
            $snapshot = $sameSelection ? $locked->snapshot : $snapshotBuilder->handleForDocuments(
                documents: $documents, ciot: $ciot, endorsements: $endorsements,
                routeUfs: $data['route_ufs'], batch: $locked->batch, driver: $driver,
                homologationFixture: $fixture,
                paymentMethod: $locked->snapshot['payment']['method'] ?? null,
                paymentBankCode: $locked->snapshot['payment']['bank_code'] ?? null,
                paymentBankAgency: $locked->snapshot['payment']['bank_agency'] ?? null,
            );
            if ($sameSelection) {
                $snapshot['loading_cities'] = $snapshotBuilder->loadingCitiesFromCiotOrigin($ciot);
            }
            $snapshot['insurance']['endorsements'] = $endorsements;
            if ($fixture !== null || ($snapshot['insurance']['source'] ?? null) === 'homologation_fixture') {
                $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle($endorsements, $fixture);
            }
            $snapshot['route_ufs'] = $data['route_ufs'];
            if ($driver !== null) {
                $snapshot['vehicle']['drivers'] = [['name' => $driver->name, 'cpf' => $driver->cpf]];
            }
            $snapshot = $this->validateSnapshot($snapshot);
            app(BuildMdfeXml::class)->handle($snapshot, (int) $locked->mdfe_number, (int) $locked->series);
            $snapshot['last_rejected_attempt'] ??= $this->rejectedAttempt($locked);
            $locked->forceFill(['snapshot' => $snapshot, 'ciot_id' => $ciot->id])->save();
            $locked->cteDocuments()->sync($selectedIds->values()->mapWithKeys(fn (int $id, int $index): array => [
                $id => ['position' => $index + 1],
            ])->all());

            return $locked->refresh();
        }, attempts: 3);
    }

    public function enqueue(MdfeDocument $document): MdfeDocument
    {
        return DB::transaction(function () use ($document): MdfeDocument {
            $locked = MdfeDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertConfirmedRejection($locked);
            $documents = $locked->cteDocuments()->get();
            $ciot = $locked->ciot;
            if ($ciot === null) {
                throw new DomainException('O CIOT vinculado não foi encontrado.');
            }
            app(ValidateMdfeDocumentSelection::class)->handle($documents, $ciot);
            $snapshot = $this->validateSnapshot($locked->snapshot);
            app(BuildMdfeXml::class)->handle($snapshot, (int) $locked->mdfe_number, (int) $locked->series);
            $snapshot['last_rejected_attempt'] ??= $this->rejectedAttempt($locked);
            app(ReserveMdfeDocuments::class)->reserve($locked, $documents, $ciot);
            $locked->forceFill([
                'status' => CteDocumentStatusEnum::QUEUED,
                'snapshot' => $snapshot,
                'payload_xml' => null, 'response_xml' => null, 'digest_value' => null,
                'fiscal_status_code' => null, 'fiscal_status_message' => null,
                'error_stage' => null, 'error_code' => null, 'error_message' => null,
                'result_payload_hash' => null,
            ])->save();
            EmitMdfeDirectJob::dispatch($locked->id)->afterCommit();

            return $locked->refresh();
        }, attempts: 3);
    }

    private function assertConfirmedRejection(MdfeDocument $document): void
    {
        if (! $this->canEdit($document)) {
            throw new DomainException('Somente MDF-e com rejeição confirmada e sem autorização pode ser editado ou reenviado.');
        }
        try {
            $response = SefazResponse::fromSoapXml($document->response_xml);
        } catch (SefazException $exception) {
            throw new DomainException('Não foi possível confirmar a rejeição na resposta da SEFAZ.', previous: $exception);
        }
        $xml = new DOMDocument;
        $xml->loadXML($document->response_xml, LIBXML_NONET);
        $environment = (new DOMXPath($xml))->evaluate('string(//*[local-name()="tpAmb"][1])');
        $key = (string) $document->access_key;
        if ($response->cStat !== (string) $document->fiscal_status_code || filled($response->protocol)
            || $environment !== (string) config('dfe.tp_amb')
            || ! preg_match('/^\d{44}$/', $key)
            || substr($key, 6, 14) !== (string) config('dfe.emit.cnpj')
            || substr($key, 20, 2) !== '58'
            || (int) substr($key, 22, 3) !== (int) $document->series
            || (int) substr($key, 25, 9) !== (int) $document->mdfe_number) {
            throw new DomainException('A rejeição, o emitente ou a numeração não correspondem ao ambiente atual.');
        }
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function validateSnapshot(array $snapshot): array
    {
        try {
            return app(ValidateMdfeSnapshot::class)->handle($snapshot);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn (array $messages, string $key): array => ['snapshot.'.$key => $messages],
            )->all());
        }
    }

    /** @return array<string, mixed> */
    private function rejectedAttempt(MdfeDocument $document): array
    {
        return [
            'number' => $document->mdfe_number, 'series' => $document->series,
            'access_key' => $document->access_key, 'payload_xml' => $document->payload_xml,
            'response_xml' => $document->response_xml, 'code' => $document->fiscal_status_code,
            'message' => $document->fiscal_status_message,
            'snapshot' => Arr::except($document->snapshot, ['last_rejected_attempt']),
        ];
    }
}
