<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveCteEmissionBatch
{
    public function handle(CteEmissionBatch $batch, User $user): CteEmissionBatch
    {
        return DB::transaction(function () use ($batch, $user): CteEmissionBatch {
            $batch = CteEmissionBatch::query()->with('documents.register')->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status === CteEmissionBatchStatusEnum::APPROVED) {
                return $batch;
            }

            if ($batch->status !== CteEmissionBatchStatusEnum::DRAFT) {
                throw ValidationException::withMessages(['batch' => 'Somente lotes em rascunho podem ser aprovados.']);
            }

            $draftDocuments = $batch->documents->where('status', CteDocumentStatusEnum::DRAFT);

            if ($draftDocuments->isEmpty()) {
                throw ValidationException::withMessages(['batch' => 'O lote não possui novos CT-es em rascunho para aprovação.']);
            }

            app(AssertCteBatchCanAcceptRegisters::class)->handle($batch);

            foreach ($draftDocuments as $document) {
                $this->ensureSnapshotStillMatches($document->register, $document->snapshot);
            }

            if ($draftDocuments->contains(fn (CteDocument $document): bool => $document->emitter !== 'direct'
                || $document->workflow_origin !== FiscalDocumentWorkflowOriginEnum::ControlledV1)) {
                throw ValidationException::withMessages([
                    'batch' => 'Este lote contém CT-e do emissor antigo e está disponível somente para consulta.',
                ]);
            }

            app(CheckCteBatchParties::class)->prepare($batch);

            $batch->forceFill([
                'status' => CteEmissionBatchStatusEnum::APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            $draftIds = $draftDocuments->modelKeys();
            $batch->documents()->whereKey($draftIds)->update(['status' => CteDocumentStatusEnum::QUEUED]);

            $this->dispatchDirectEmission($batch->refresh()->load('documents'), $draftIds);

            return $batch->refresh()->load('documents');
        });
    }

    protected function dispatchDirectEmission(CteEmissionBatch $batch, array $draftIds): void
    {
        foreach ($batch->documents->whereIn('id', $draftIds) as $document) {
            if ($document->execution_mode !== 'live') {
                // dry_run no emissor direto: nada transmite à SEFAZ.
                app(DirectCteDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::DRY_RUN_COMPLETED);

                continue;
            }

            EmitCteDirectJob::dispatch($document->id)->afterCommit();
        }

        app(CteBatchStatusUpdater::class)->handle($batch);
    }

    /** @param array<string, mixed> $snapshot */
    private function ensureSnapshotStillMatches(Register $register, array $snapshot): void
    {
        $fields = [
            'vehicle_id', 'vehicle_model', 'vehicle_plate', 'origin_city',
            'destination_city', 'payment_code', 'insurance', 'fipe_value', 'value',
        ];

        foreach ($fields as $field) {
            $current = $register->{$field};
            $current = $current === null ? null : (string) $current;
            $snapshotValue = $snapshot[$field] ?? null;
            $matches = in_array($field, ['fipe_value', 'value'], true)
                ? $this->normalizeDecimal($current) === $this->normalizeDecimal($snapshotValue)
                : $current === $snapshotValue;

            if (! $matches) {
                throw ValidationException::withMessages([
                    'batch' => "A remocao {$register->id} foi alterada depois da criacao do lote. Crie um novo lote.",
                ]);
            }
        }
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }
}
