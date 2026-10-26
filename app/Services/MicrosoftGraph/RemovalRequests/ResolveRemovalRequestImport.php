<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ResolveRemovalRequestImport
{
    private const EDITABLE_FIELDS = [
        'vehicle_model',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'value',
        'insurance',
        'fipe_value',
        'payment_code',
        'notes',
    ];

    public function __construct(
        private readonly RemovalRequestPdfStorage $storage,
    ) {}

    /**
     * @param  list<string>  $selectedFields
     */
    public function apply(
        IntegrationInboxItem $item,
        User $user,
        array $selectedFields,
        bool $replacePdf,
    ): IntegrationInboxItem {
        $oldPdfPath = null;
        $candidatePath = null;
        $pdfWasReplaced = false;
        $item = DB::transaction(function () use ($item, $user, $selectedFields, $replacePdf, &$oldPdfPath, &$candidatePath, &$pdfWasReplaced): IntegrationInboxItem {
            $lockedItem = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->message_type !== 'removal_request' || $lockedItem->status !== 'pending') {
                throw new DomainException('Somente importações pendentes podem ser revisadas.');
            }

            $register = Register::query()->lockForUpdate()->findOrFail($lockedItem->register_id);
            $proposedChanges = is_array($lockedItem->proposed_changes) ? $lockedItem->proposed_changes : [];
            $selectedFields = array_values(array_intersect($selectedFields, array_keys($proposedChanges), self::EDITABLE_FIELDS));

            if ($selectedFields === [] && ! $replacePdf) {
                throw new DomainException('Selecione pelo menos uma alteração ou o PDF candidato.');
            }

            $attributes = $register->only(self::EDITABLE_FIELDS);
            $candidatePath = $lockedItem->candidate_pdf_path;

            foreach ($selectedFields as $field) {
                $attributes[$field] = $this->valueFromProposal($field, $proposedChanges[$field]['proposed'] ?? null);
            }

            $this->validateAttributes($attributes);

            if ($replacePdf) {
                if ($candidatePath === null || $lockedItem->candidate_pdf_sha256 === null) {
                    throw new DomainException('Não existe PDF candidato para substituir.');
                }

                $oldPdfPath = $register->pdf_path;
                $attributes['pdf_path'] = $candidatePath;
                $attributes['pdf_sha256'] = $lockedItem->candidate_pdf_sha256;
                $pdfWasReplaced = true;
            }

            $register->forceFill($attributes)->save();

            $lockedItem->forceFill([
                'status' => 'processed',
                'failure_reason' => null,
                'proposed_changes' => null,
                'alerts' => null,
                'candidate_pdf_path' => null,
                'candidate_pdf_sha256' => null,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();

            return $lockedItem->refresh();
        });

        if ($candidatePath !== null) {
            $this->deleteAfterResolution(
                $item,
                $pdfWasReplaced && $oldPdfPath !== null && $oldPdfPath !== $candidatePath
                    ? $oldPdfPath
                    : $candidatePath,
            );
        }

        return $item->refresh();
    }

    public function reject(IntegrationInboxItem $item, User $user, string $reason): IntegrationInboxItem
    {
        $candidatePath = DB::transaction(function () use ($item, $user, $reason): ?string {
            $lockedItem = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->message_type !== 'removal_request' || ! in_array($lockedItem->status, ['pending', 'alert'], true)) {
                throw new DomainException('Somente importações abertas podem ser rejeitadas.');
            }

            $candidatePath = $lockedItem->candidate_pdf_path;
            $lockedItem->forceFill([
                'status' => 'rejected',
                'failure_reason' => trim($reason),
                'candidate_pdf_path' => null,
                'candidate_pdf_sha256' => null,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();

            return $candidatePath;
        });

        if ($candidatePath !== null) {
            $this->deleteAfterResolution($item, $candidatePath);
        }

        return $item->refresh();
    }

    public function acknowledge(IntegrationInboxItem $item, User $user): IntegrationInboxItem
    {
        $item->refresh();

        if ($item->message_type !== 'removal_request' || $item->status !== 'alert') {
            throw new DomainException('Somente alertas de importação podem ser reconhecidos.');
        }

        $item->forceFill([
            'status' => 'processed',
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();

        return $item->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function validateAttributes(array $attributes): void
    {
        Validator::make($attributes, [
            'vehicle_model' => ['required', 'string', 'max:30'],
            'origin_city' => ['required', 'string', 'max:50'],
            'destination_city' => ['required', 'string', 'max:50'],
            'deadline_withdraw' => ['required', 'date'],
            'deadline_delivery' => ['required', 'date'],
            'value' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'fipe_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'insurance' => ['required', 'string'],
            'payment_code' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1500'],
        ])->validate();
    }

    private function valueFromProposal(string $field, mixed $value): mixed
    {
        if (in_array($field, ['deadline_withdraw', 'deadline_delivery'], true) && is_string($value)) {
            return Carbon::parse($value)->toDateString();
        }

        return $value;
    }

    private function deleteAfterResolution(IntegrationInboxItem $item, string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (\Throwable $exception) {
            Log::error('Falha ao remover PDF após revisão de importação.', [
                'integration_inbox_item_id' => $item->id,
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
