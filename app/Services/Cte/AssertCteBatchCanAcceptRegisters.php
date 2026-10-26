<?php

namespace App\Services\Cte;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AssertCteBatchCanAcceptRegisters
{
    public function handle(CteEmissionBatch $batch): void
    {
        $cteDocuments = $batch->documents()->orderBy('id')->lockForUpdate()->get();
        $cteDocumentIds = $cteDocuments->modelKeys();

        if ($cteDocuments->contains(fn (CteDocument $document): bool => ! in_array($document->status, [
            CteDocumentStatusEnum::DRAFT,
            CteDocumentStatusEnum::AUTHORIZED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            CteDocumentStatusEnum::CANCELLED,
            CteDocumentStatusEnum::SUPERSEDED,
        ], true))) {
            throw ValidationException::withMessages(['batch' => 'A inclusão está bloqueada enquanto houver CT-e em processamento ou com resultado incerto.']);
        }

        if ($cteDocuments->contains(fn (CteDocument $document): bool => $document->status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION
            && (filled($document->access_key) || filled($document->payload_xml))
            && $document->fiscal_status !== FiscalDocumentStatusEnum::CANCELLED
            && $document->fiscal_status_code !== '101')) {
            throw ValidationException::withMessages(['batch' => 'A inclusão está bloqueada enquanto houver CT-e com evidência de transmissão sem resultado fiscal confirmado.']);
        }

        if ($cteDocuments->contains(fn (CteDocument $document): bool => $document->emitter !== 'direct'
            || $document->workflow_origin !== FiscalDocumentWorkflowOriginEnum::ControlledV1)) {
            throw ValidationException::withMessages(['batch' => 'Este lote contém CT-e do emissor antigo e está disponível somente para consulta.']);
        }

        $mdfes = MdfeDocument::query()
            ->where(function (Builder $query) use ($batch, $cteDocumentIds): void {
                $query->where('cte_emission_batch_id', $batch->id)
                    ->orWhereHas('cteDocuments', fn (Builder $documents): Builder => $documents->whereIn('cte_documents.id', $cteDocumentIds));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $ciots = Ciot::query()
            ->where(function (Builder $query) use ($batch, $mdfes): void {
                $query->where('cte_emission_batch_id', $batch->id)
                    ->orWhereHas('mdfeDocuments', fn (Builder $documents): Builder => $documents->whereIn('mdfe_documents.id', $mdfes->modelKeys()));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($ciots->contains(fn (Ciot $ciot): bool => in_array($ciot->status, [
            CiotStatusEnum::ISSUED,
            CiotStatusEnum::CLOSED,
            CiotStatusEnum::PENDING,
        ], true))) {
            throw ValidationException::withMessages(['batch' => 'O lote possui CIOT emitido, encerrado ou em processamento e não pode ser alterado ou aprovado.']);
        }

        if ($ciots->contains(fn (Ciot $ciot): bool => in_array($ciot->status, [
            CiotStatusEnum::DRAFT,
            CiotStatusEnum::FAILED,
        ], true))) {
            throw ValidationException::withMessages(['batch' => 'Remova e recrie o CIOT em rascunho ou com falha antes de incluir registros, para evitar o uso de dados desatualizados.']);
        }

        $activeMdfeStatuses = [
            CteDocumentStatusEnum::QUEUED,
            CteDocumentStatusEnum::CLAIMED,
            CteDocumentStatusEnum::FILLING,
            CteDocumentStatusEnum::VALIDATING,
            CteDocumentStatusEnum::READY_TO_AUTHORIZE,
            CteDocumentStatusEnum::AUTHORIZING,
            CteDocumentStatusEnum::WAITING_FOR_XML,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ];

        if ($mdfes->contains(fn (MdfeDocument $mdfe): bool => $this->isUncanceledFiscalResult($mdfe)
            && (in_array($mdfe->status, $activeMdfeStatuses, true)
                || $mdfe->fiscal_status === FiscalDocumentStatusEnum::CLOSED
                || $mdfe->fiscal_status_code === '132'
                || $mdfe->status === CteDocumentStatusEnum::AUTHORIZED
                || $mdfe->fiscal_status === FiscalDocumentStatusEnum::AUTHORIZED))) {
            throw ValidationException::withMessages(['batch' => 'O lote possui MDF-e emitido, encerrado ou em processamento e não pode ser alterado ou aprovado.']);
        }

        if ($mdfes->contains(fn (MdfeDocument $mdfe): bool => $mdfe->status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION
            && $this->isUncanceledFiscalResult($mdfe)
            && (filled($mdfe->access_key) || filled($mdfe->payload_xml)))) {
            throw ValidationException::withMessages(['batch' => 'A inclusão está bloqueada enquanto houver MDF-e com evidência de transmissão sem resultado fiscal confirmado.']);
        }

        foreach ($mdfes as $mdfe) {
            if ($mdfe->events()->whereIn('status', ['pending', 'reconciliation_required', 'duplicate_reconciliation'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['batch' => 'A inclusão está bloqueada enquanto houver evento de MDF-e sem resultado confirmado.']);
            }
        }
    }

    private function isUncanceledFiscalResult(MdfeDocument $mdfe): bool
    {
        return $mdfe->fiscal_status !== FiscalDocumentStatusEnum::CANCELLED
            && $mdfe->fiscal_status_code !== '101';
    }
}
