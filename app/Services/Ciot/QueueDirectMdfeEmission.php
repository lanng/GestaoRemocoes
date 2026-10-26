<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Dfe\ReserveMdfeDocuments;
use App\Services\Dfe\ValidateMdfeSnapshot;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QueueDirectMdfeEmission
{
    public function __construct(
        private readonly BuildMdfeEmissionSnapshot $snapshotBuilder,
        private readonly ValidateMdfeDocumentSelection $selectionValidator,
        private readonly ReserveMdfeDocuments $reservationService,
        private readonly ValidateMdfeSnapshot $snapshotValidator,
    ) {}

    /**
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     */
    public function handle(
        CteEmissionBatch $batch,
        array $endorsements,
        array $routeUfs,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): MdfeDocument {
        $ciot = $batch->ciots()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->latest('id')
            ->get()
            ->first(fn (Ciot $candidate): bool => ! $candidate->isSyntheticHomologation());

        if (! $ciot instanceof Ciot) {
            throw new DomainException('CIOT emitido não encontrado para este lote.');
        }

        return $this->handleForDocuments(
            documents: $batch->documents()->get(),
            ciot: $ciot,
            endorsements: $endorsements,
            routeUfs: $routeUfs,
            batch: $batch,
            driver: $driver,
            homologationFixture: $homologationFixture,
            paymentMethod: $paymentMethod,
            paymentBankCode: $paymentBankCode,
            paymentBankAgency: $paymentBankAgency,
        );
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     */
    public function handleForDocuments(
        Collection $documents,
        Ciot $ciot,
        array $endorsements,
        array $routeUfs,
        ?CteEmissionBatch $batch = null,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): MdfeDocument {
        return DB::transaction(function () use (
            $documents,
            $ciot,
            $endorsements,
            $routeUfs,
            $batch,
            $driver,
            $homologationFixture,
            $paymentMethod,
            $paymentBankCode,
            $paymentBankAgency,
        ): MdfeDocument {
            $documentIds = $documents->map(fn (CteDocument $document): int => (int) $document->id)->values();

            if ($documentIds->isEmpty() || $documentIds->contains(0) || $documentIds->unique()->count() !== $documentIds->count()) {
                throw new DomainException('Selecione CT-es persistidos e sem duplicação para preparar o MDF-e.');
            }

            $batchIds = CteDocument::query()
                ->whereIn('id', $documentIds)
                ->pluck('cte_emission_batch_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->merge($batch === null ? [] : [(int) $batch->id])
                ->unique()
                ->sort()
                ->values();
            $lockedBatches = $batchIds->isEmpty()
                ? collect()
                : CteEmissionBatch::query()->whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($lockedBatches->count() !== $batchIds->count()) {
                throw new DomainException('O lote de origem não existe mais. Atualize a página e tente novamente.');
            }

            if ($lockedBatches->contains(fn (CteEmissionBatch $sourceBatch): bool => $sourceBatch->status === CteEmissionBatchStatusEnum::DRAFT)) {
                throw new DomainException('O MDF-e não pode ser preparado enquanto o lote de CT-e estiver em revisão.');
            }

            $lockedDocuments = CteDocument::query()
                ->whereIn('id', $documentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedDocuments->count() !== $documentIds->count()) {
                throw new DomainException('Um ou mais CT-es selecionados não existem mais. Atualize a seleção.');
            }

            $documents = $documentIds->map(fn (int $id): CteDocument => $lockedDocuments->get($id))->values();
            $lockedBatch = $batch === null ? null : $lockedBatches->get($batch->id);

            $this->selectionValidator->handle($documents, $ciot);
            $snapshot = $this->snapshotBuilder->handleForDocuments(
                documents: $documents,
                ciot: $ciot,
                endorsements: $endorsements,
                routeUfs: $routeUfs,
                batch: $lockedBatch,
                driver: $driver,
                homologationFixture: $homologationFixture,
                paymentMethod: $paymentMethod,
                paymentBankCode: $paymentBankCode,
                paymentBankAgency: $paymentBankAgency,
            );
            $this->snapshotValidator->assertLoadingCitiesMatchOrigin($snapshot);
            $mdfe = MdfeDocument::query()->create([
                'public_id' => (string) Str::uuid(),
                'cte_emission_batch_id' => $lockedBatch?->id,
                'ciot_id' => $ciot->id,
                'status' => CteDocumentStatusEnum::QUEUED,
                'snapshot' => $snapshot,
                'idempotency_key' => (string) Str::uuid(),
                'execution_mode' => 'live',
                'emitter' => 'direct',
                'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
            ]);

            $mdfe->cteDocuments()->attach($documents
                ->values()
                ->mapWithKeys(fn (CteDocument $document, int $position): array => [
                    $document->id => ['position' => $position + 1],
                ])
                ->all());

            $this->reservationService->reserve($mdfe, $documents, $ciot);

            EmitMdfeDirectJob::dispatch($mdfe->id)->afterCommit();

            return $mdfe;
        });
    }
}
