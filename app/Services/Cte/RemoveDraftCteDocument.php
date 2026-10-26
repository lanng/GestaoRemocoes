<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\DfeDocumentReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveDraftCteDocument
{
    public function handle(CteDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $batchId = CteDocument::query()
                ->whereKey($document->id)
                ->value('cte_emission_batch_id');
            $batch = $batchId === null
                ? null
                : CteEmissionBatch::query()->lockForUpdate()->findOrFail($batchId);
            $lockedDocument = CteDocument::query()
                ->lockForUpdate()
                ->findOrFail($document->id);

            if ($lockedDocument->cte_emission_batch_id !== $batch?->id) {
                throw ValidationException::withMessages([
                    'document' => 'O documento não pertence ao lote informado.',
                ]);
            }

            if ($batch !== null && $batch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages([
                    'batch' => 'Somente lotes em rascunho podem ter documentos retirados.',
                ]);
            }

            $this->validateDocumentCanBeRemoved($lockedDocument);

            $lockedDocument->nfeDocuments()->delete();
            $lockedDocument->delete();

            if ($batch !== null && ! $batch->documents()->exists()) {
                $batch->delete();
            } elseif ($batch !== null) {
                app(CteBatchStatusUpdater::class)->handle($batch->refresh());
            }
        });
    }

    public function canRemove(CteDocument $document): bool
    {
        $document = CteDocument::query()->find($document->id);

        if ($document === null || ! $this->hasNoFiscalOrTransmissionEvidence($document)) {
            return false;
        }

        if ($document->cte_emission_batch_id !== null
            && CteEmissionBatch::query()
                ->whereKey($document->cte_emission_batch_id)
                ->where('status', CteEmissionBatchStatusEnum::DRAFT->value)
                ->doesntExist()) {
            return false;
        }

        return ! $document->events()->exists()
            && ! $document->mdfeDocuments()->exists()
            && ! DfeDocumentReservation::query()
                ->where('resource_type', 'cte')
                ->where('resource_id', $document->id)
                ->exists();
    }

    private function validateDocumentCanBeRemoved(CteDocument $document): void
    {
        if (! $this->hasNoFiscalOrTransmissionEvidence($document)) {
            throw ValidationException::withMessages([
                'document' => 'Somente rascunhos sem número, chave ou evidência de comunicação fiscal podem ser excluídos.',
            ]);
        }

        if ($document->events()->exists()
            || $document->mdfeDocuments()->exists()
            || DfeDocumentReservation::query()
                ->where('resource_type', 'cte')
                ->where('resource_id', $document->id)
                ->exists()) {
            throw ValidationException::withMessages([
                'document' => 'O CT-e possui eventos fiscais ou vínculos com MDF-e e não pode ser excluído.',
            ]);
        }
    }

    private function hasNoFiscalOrTransmissionEvidence(CteDocument $document): bool
    {
        if ($document->status !== CteDocumentStatusEnum::DRAFT
            || ! in_array($document->fiscal_status, [null, FiscalDocumentStatusEnum::NOT_AUTHORIZED], true)) {
            return false;
        }

        foreach ([
            'authorization_started_at', 'issued_at', 'authorized_at', 'cte_number', 'access_key',
            'protocol', 'fiscal_status_code', 'fiscal_status_message', 'payload_xml', 'response_xml',
            'digest_value', 'xml_path', 'pdf_path', 'sha256', 'error_stage', 'error_code',
            'error_message', 'result_payload_hash',
        ] as $attribute) {
            if (filled($document->{$attribute})) {
                return false;
            }
        }

        return true;
    }
}
