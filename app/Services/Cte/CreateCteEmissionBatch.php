<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCteEmissionBatch
{
    /**
     * @param  Collection<int, Register>  $registers
     */
    public function handle(
        Collection $registers,
        User $user,
        string $executionMode,
        bool $reemissionConfirmed = false,
        ?string $reemissionReason = null,
    ): CteEmissionBatch {
        if (! in_array($executionMode, ['dry_run', 'live'], true)) {
            throw ValidationException::withMessages(['execution_mode' => 'Modo de execucao invalido.']);
        }

        if ($registers->isEmpty()) {
            throw ValidationException::withMessages(['registers' => 'Selecione ao menos uma remocao.']);
        }

        return DB::transaction(function () use ($registers, $user, $executionMode, $reemissionConfirmed, $reemissionReason): CteEmissionBatch {
            $batch = CteEmissionBatch::query()->create([
                'status' => CteEmissionBatchStatusEnum::DRAFT,
                'execution_mode' => $executionMode,
                'created_by' => $user->id,
            ]);

            $lockedRegisters = Register::query()
                ->whereIn('id', $registers->pluck('id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($registers as $selected) {
                $register = $lockedRegisters->get($selected->id);

                if ($register === null) {
                    throw ValidationException::withMessages([
                        'registers' => "A remocao {$selected->id} nao foi encontrada.",
                    ]);
                }

                $authorizedDocument = $register->cteDocuments()
                    ->where('status', CteDocumentStatusEnum::AUTHORIZED)
                    ->orderByDesc('authorized_at')
                    ->orderByDesc('id')
                    ->first();

                if ($authorizedDocument !== null && ! $reemissionConfirmed) {
                    throw ValidationException::withMessages([
                        'registers' => "A remocao {$register->id} ja possui um CT-e autorizado.",
                    ]);
                }

                $this->createDraftDocument($batch, $register, $executionMode, $authorizedDocument, $reemissionReason);
            }

            return $batch->load('documents');
        });
    }

    /**
     * @param  Collection<int, Register>  $registers
     */
    public function addRegistersToBatch(CteEmissionBatch $batch, Collection $registers): CteEmissionBatch
    {
        return DB::transaction(function () use ($batch, $registers): CteEmissionBatch {
            $lockedBatch = CteEmissionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! in_array($lockedBatch->status, [
                CteEmissionBatchStatusEnum::DRAFT,
                CteEmissionBatchStatusEnum::COMPLETED,
                CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            ], true)) {
                throw ValidationException::withMessages(['batch' => 'Somente lotes em rascunho ou concluídos podem receber registros.']);
            }

            if ($registers->isEmpty()) {
                throw ValidationException::withMessages(['registers' => 'Selecione ao menos um registro.']);
            }

            $registerIds = $registers->map(fn (Register $register): int => (int) $register->id);

            if ($registerIds->unique()->count() !== $registerIds->count()
                || $lockedBatch->documents()->whereIn('register_id', $registerIds)->exists()) {
                throw ValidationException::withMessages(['registers' => 'Um ou mais registros já estão neste lote.']);
            }

            app(AssertCteBatchCanAcceptRegisters::class)->handle($lockedBatch);

            $lockedRegisters = Register::query()
                ->whereIn('id', $registerIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedRegisters->count() !== $registerIds->count()) {
                throw ValidationException::withMessages(['registers' => 'Um ou mais registros não foram encontrados.']);
            }

            $blockedStatuses = [
                CteDocumentStatusEnum::DRAFT,
                CteDocumentStatusEnum::QUEUED,
                CteDocumentStatusEnum::CLAIMED,
                CteDocumentStatusEnum::FILLING,
                CteDocumentStatusEnum::VALIDATING,
                CteDocumentStatusEnum::READY_TO_AUTHORIZE,
                CteDocumentStatusEnum::AUTHORIZING,
                CteDocumentStatusEnum::WAITING_FOR_XML,
                CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
                CteDocumentStatusEnum::AUTHORIZED,
                CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            ];
            $existingDocuments = CteDocument::query()
                ->whereIn('register_id', $registerIds)
                ->whereIn('status', $blockedStatuses)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existingDocuments->contains(fn (CteDocument $document): bool => $document->status !== CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION
                || ((filled($document->access_key) || filled($document->payload_xml))
                    && $document->fiscal_status !== FiscalDocumentStatusEnum::CANCELLED
                    && $document->fiscal_status_code !== '101'))) {
                throw ValidationException::withMessages(['registers' => 'Um ou mais registros já têm CT-e autorizado, em outro lote ativo ou com resultado incerto.']);
            }

            foreach ($registerIds as $registerId) {
                $register = $lockedRegisters->get($registerId);
                $this->createDraftDocument($lockedBatch, $register, $lockedBatch->execution_mode);
            }

            $lockedBatch->forceFill([
                'status' => CteEmissionBatchStatusEnum::DRAFT,
                'approved_by' => null,
                'approved_at' => null,
                'processing_started_at' => null,
                'completed_at' => null,
            ])->save();

            return $lockedBatch->refresh()->load('documents');
        });
    }

    private function createDraftDocument(
        CteEmissionBatch $batch,
        Register $register,
        string $executionMode,
        ?CteDocument $authorizedDocument = null,
        ?string $reemissionReason = null,
    ): CteDocument {
        $this->validateRegister($register);

        return $batch->documents()->create([
            'public_id' => (string) Str::uuid(),
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::DRAFT,
            'snapshot' => $this->snapshot($register),
            'idempotency_key' => (string) Str::uuid(),
            'execution_mode' => $executionMode,
            'emitter' => 'direct',
            'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
            'replaced_document_id' => $authorizedDocument?->id,
            'replacement_reason' => $authorizedDocument !== null ? $reemissionReason : null,
        ]);
    }

    /** @return array<string, string|int|null> */
    private function snapshot(Register $register): array
    {
        return [
            'schema_version' => 1,
            'register_id' => $register->id,
            'vehicle_id' => (string) $register->vehicle_id,
            'company' => $register->company?->value ?? (string) $register->company,
            'vehicle_model' => (string) $register->vehicle_model,
            'vehicle_plate' => (string) $register->vehicle_plate,
            'origin_city' => (string) $register->origin_city,
            'destination_city' => (string) $register->destination_city,
            'payment_code' => (string) $register->payment_code,
            'insurance' => (string) $register->insurance,
            'fipe_value' => $register->fipe_value !== null ? (string) $register->fipe_value : null,
            'value' => (string) $register->value,
        ];
    }

    private function validateRegister(Register $register): void
    {
        $required = [
            'vehicle_id', 'vehicle_model', 'vehicle_plate', 'origin_city',
            'destination_city', 'payment_code', 'insurance', 'fipe_value', 'value',
        ];

        foreach ($required as $field) {
            if (blank($register->{$field})) {
                throw ValidationException::withMessages([
                    'registers' => "O campo {$field} e obrigatorio na remocao {$register->id}.",
                ]);
            }
        }
    }
}
