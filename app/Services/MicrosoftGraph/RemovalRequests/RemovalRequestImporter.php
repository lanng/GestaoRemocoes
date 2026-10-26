<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Enums\CompanyEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @phpstan-type RemovalChangeValue scalar|array<string, scalar|null>|null
 * @phpstan-type RemovalChange array{current: RemovalChangeValue, proposed: RemovalChangeValue}
 */
class RemovalRequestImporter
{
    private const IMPORT_LOCK_TTL = 600;

    private const IMPORT_LOCK_WAIT = 1;

    private const REGISTER_FIELDS = [
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'vehicle_id',
        'value',
        'insurance',
        'fipe_value',
        'payment_code',
        'notes',
    ];

    private const BODY_REQUIRED_FIELDS = [
        'vehicle_id',
        'insurance',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'value',
        'fipe_value',
        'payment_code',
    ];

    private const PDF_REQUIRED_FIELDS = [
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'vehicle_id',
        'insurance',
        'deadline_withdraw',
        'deadline_delivery',
    ];

    public function __construct(
        private readonly RemovalRequestNormalizer $normalizer,
        private readonly RemovalRequestPdfStorage $storage,
    ) {}

    public function handle(IntegrationInboxItem $item, PreparedRemovalPdf $pdf): IntegrationInboxItem
    {
        $sources = $this->sources($item, $pdf);
        $item->extracted_data = $sources;

        $canonical = $this->canonical($sources);
        $failureReason = $this->validationFailure($sources, $canonical);

        if ($failureReason !== null) {
            $oldCandidatePath = $item->candidate_pdf_path;
            $result = $this->markPending($item, $failureReason);

            $this->cleanupAfterCommit($result, null, $oldCandidatePath);

            return $result->refresh();
        }

        return Cache::lock(
            'removal-request-import:plate:'.$canonical['vehicle_plate'],
            self::IMPORT_LOCK_TTL,
        )->block(self::IMPORT_LOCK_WAIT, function () use ($item, $pdf, $canonical): IntegrationInboxItem {
            $identity = $this->resolveIdentity($canonical['vehicle_id'], $canonical['vehicle_plate']);

            if ($identity === null) {
                return $this->markPending($item, 'identity_conflict');
            }

            $uploadedPath = $this->uploadBeforeTransaction($item, $pdf, $canonical, $identity);

            try {
                $result = DB::transaction(function () use ($item, $pdf, $canonical, $uploadedPath): array {
                    $lockedIdentity = $this->resolveIdentity(
                        $canonical['vehicle_id'],
                        $canonical['vehicle_plate'],
                        true,
                    );

                    if ($lockedIdentity === null) {
                        $oldCandidatePath = $item->candidate_pdf_path;

                        return [
                            'item' => $this->markPending($item, 'identity_conflict'),
                            'old_pdf_path' => null,
                            'old_candidate_path' => $oldCandidatePath,
                            'discard_upload' => true,
                        ];
                    }

                    if ($lockedIdentity instanceof Register) {
                        return $this->persistExisting(
                            $item,
                            $pdf,
                            $canonical,
                            $lockedIdentity,
                            $uploadedPath,
                        );
                    }

                    return $this->persistNew($item, $pdf, $canonical, $uploadedPath);
                });

                if ($result['discard_upload'] && $uploadedPath !== null) {
                    $this->cleanupAfterCommit($result['item'], null, $uploadedPath);
                    $uploadedPath = null;
                }

                $this->cleanupAfterCommit(
                    $result['item'],
                    $result['old_pdf_path'],
                    $result['old_candidate_path'],
                );

                return $result['item']->refresh();
            } catch (Throwable $exception) {
                if ($uploadedPath !== null) {
                    try {
                        $this->storage->delete($uploadedPath);
                    } catch (Throwable $cleanupException) {
                        throw new \RuntimeException(
                            'Falha ao compensar o upload do PDF: '.$cleanupException->getMessage(),
                            0,
                            $exception,
                        );
                    }
                }

                throw $exception;
            }
        });
    }

    /** @param array<string, string|null> $canonical */
    private function uploadBeforeTransaction(
        IntegrationInboxItem $item,
        PreparedRemovalPdf $pdf,
        array $canonical,
        Register|false $identity,
    ): ?string {
        if (! $identity instanceof Register) {
            return $this->storage->store($pdf, $canonical['vehicle_id']);
        }

        $changes = $this->changes($identity, $canonical, $pdf);
        $pdfChanged = array_key_exists('pdf_path', $changes);

        if (! $pdfChanged) {
            return null;
        }

        if ($this->canUpdateRegister($identity)
            || $identity->status === RegisterStatusEnum::CANCELLED) {
            return $this->storage->store($pdf, $canonical['vehicle_id']);
        }

        if ($item->candidate_pdf_sha256 === $pdf->sha256 && $item->candidate_pdf_path !== null) {
            return null;
        }

        return $this->storage->store($pdf, $canonical['vehicle_id']);
    }

    /** @return array<string, mixed> */
    private function sources(IntegrationInboxItem $item, PreparedRemovalPdf $pdf): array
    {
        $data = is_array($item->extracted_data) ? $item->extracted_data : [];
        $subject = is_array($data['subject'] ?? null) ? $data['subject'] : [];
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];

        return [
            'subject' => $subject,
            'body' => $body,
            'body_missing_fields' => is_array($data['body_missing_fields'] ?? null)
                ? $data['body_missing_fields']
                : [],
            'pdf' => $pdf->extractedData,
        ];
    }

    /** @param array<string, mixed> $sources @return array<string, string|null> */
    private function canonical(array $sources): array
    {
        $subject = $sources['subject'];
        $body = $sources['body'];
        $pdf = $sources['pdf'];

        return [
            'vehicle_model' => $this->normalizer->text($pdf['vehicle_model'] ?? null),
            'vehicle_plate' => $this->normalizer->plate($pdf['vehicle_plate'] ?? null),
            'origin_city' => $this->normalizer->text($pdf['origin_city'] ?? null),
            'destination_city' => $this->normalizer->text($this->withoutState($body['destination_city'] ?? null)),
            'deadline_withdraw' => $this->normalizer->date($body['deadline_withdraw'] ?? null),
            'deadline_delivery' => $this->normalizer->date($body['deadline_delivery'] ?? null),
            'vehicle_id' => $this->normalizer->identifier($body['vehicle_id'] ?? null),
            'value' => $this->normalizer->decimal($body['value'] ?? null),
            'insurance' => $this->normalizer->insurance($subject['insurance'] ?? $body['insurance'] ?? null),
            'fipe_value' => $this->normalizer->decimal($body['fipe_value'] ?? null),
            'payment_code' => $this->normalizer->identifier($body['payment_code'] ?? null),
        ];
    }

    /** @param array<string, mixed> $sources @param array<string, string|null> $canonical */
    private function validationFailure(array $sources, array $canonical): ?string
    {
        $body = $sources['body'];
        $pdf = $sources['pdf'];
        $missingBody = array_values(array_filter(
            self::BODY_REQUIRED_FIELDS,
            fn (string $field): bool => $this->isBlankSource($body, $field),
        ));

        if ($missingBody !== [] || $sources['body_missing_fields'] !== []) {
            return 'missing_body_fields';
        }

        $missingPdf = array_values(array_filter(
            self::PDF_REQUIRED_FIELDS,
            fn (string $field): bool => $this->isBlankSource($pdf, $field),
        ));

        if ($missingPdf !== []) {
            return 'missing_pdf_fields';
        }

        foreach ([[$body, self::BODY_REQUIRED_FIELDS], [$pdf, self::PDF_REQUIRED_FIELDS]] as [$source, $fields]) {
            foreach ($fields as $field) {
                if ($this->sourceValue($field, $source) === null) {
                    return 'invalid_constraints';
                }
            }
        }

        $comparisons = [
            ['vehicle_plate', $sources['subject']['vehicle_plate'] ?? null, $pdf['vehicle_plate'] ?? null, 'vehicle_plate_mismatch'],
            ['vehicle_id', $sources['subject']['vehicle_id'] ?? null, $body['vehicle_id'] ?? null, 'vehicle_id_mismatch'],
            ['vehicle_id', $body['vehicle_id'] ?? null, $pdf['vehicle_id'] ?? null, 'vehicle_id_mismatch'],
            ['insurance', $sources['subject']['insurance'] ?? null, $body['insurance'] ?? null, 'insurance_mismatch'],
            ['insurance', $sources['subject']['insurance'] ?? null, $pdf['insurance'] ?? null, 'insurance_mismatch'],
            ['destination_city', $body['destination_city'] ?? null, $pdf['destination_city'] ?? null, 'destination_city_mismatch'],
            ['deadline_withdraw', $body['deadline_withdraw'] ?? null, $pdf['deadline_withdraw'] ?? null, 'deadline_withdraw_mismatch'],
            ['deadline_delivery', $body['deadline_delivery'] ?? null, $pdf['deadline_delivery'] ?? null, 'deadline_delivery_mismatch'],
        ];

        foreach ($comparisons as [$field, $left, $right, $failureReason]) {
            if (
                $this->sourceValue($field, [$field => $left]) === null
                || $this->sourceValue($field, [$field => $right]) === null
                || $this->sourceValue($field, [$field => $left]) !== $this->sourceValue($field, [$field => $right])
            ) {
                return $failureReason;
            }
        }

        if (
            $canonical['vehicle_model'] === null
            || mb_strlen($canonical['vehicle_model']) > 30
            || $canonical['vehicle_plate'] === null
            || preg_match('/^[A-Z0-9]{7}$/', $canonical['vehicle_plate']) !== 1
            || $canonical['origin_city'] === null
            || mb_strlen($canonical['origin_city']) > 50
            || $canonical['destination_city'] === null
            || mb_strlen($canonical['destination_city']) > 50
            || $canonical['vehicle_id'] === null
            || preg_match('/^\d{1,10}$/', $canonical['vehicle_id']) !== 1
            || $this->exceedsDecimal($canonical['value'], '9999.99')
            || $this->exceedsDecimal($canonical['fipe_value'], '999999.99')
            || $canonical['payment_code'] === null
            || $canonical['insurance'] === null
        ) {
            return 'invalid_constraints';
        }

        return null;
    }

    private function exceedsDecimal(?string $value, string $maximum): bool
    {
        if ($value === null) {
            return true;
        }

        [$valueInteger, $valueFraction] = array_pad(explode('.', $value, 2), 2, '00');
        [$maximumInteger, $maximumFraction] = array_pad(explode('.', $maximum, 2), 2, '00');
        $valueInteger = ltrim($valueInteger, '0') ?: '0';
        $maximumInteger = ltrim($maximumInteger, '0') ?: '0';

        if (strlen($valueInteger) !== strlen($maximumInteger)) {
            return strlen($valueInteger) > strlen($maximumInteger);
        }

        return ($valueInteger.$valueFraction) > ($maximumInteger.$maximumFraction);
    }

    /** @param array<string, mixed> $source */
    private function isBlankSource(array $source, string $field): bool
    {
        $value = $source[$field] ?? null;

        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function sourceValue(string $field, mixed $source): mixed
    {
        $value = is_array($source) ? ($source[$field] ?? null) : null;

        return match ($field) {
            'vehicle_plate' => $this->normalizer->plate(is_scalar($value) ? (string) $value : null),
            'vehicle_id', 'payment_code' => $this->normalizer->identifier(is_scalar($value) ? (string) $value : null),
            'insurance' => $this->normalizer->insurance(is_scalar($value) ? (string) $value : null),
            'destination_city' => $this->normalizer->text($this->withoutState(is_scalar($value) ? (string) $value : null)),
            'deadline_withdraw', 'deadline_delivery' => $this->normalizer->date(is_scalar($value) ? (string) $value : null),
            'value', 'fipe_value' => $this->normalizer->decimal(is_scalar($value) ? (string) $value : null),
            default => $this->normalizer->text(is_scalar($value) ? (string) $value : null),
        };
    }

    private function withoutState(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\s+-\s+[A-Z]{2}\s*$/iu', '', $value) ?: $value;
    }

    private function resolveIdentity(string $vehicleId, string $plate, bool $lockForUpdate = false): Register|false|null
    {
        $query = Register::query()
            ->where('company', CompanyEnum::COPART->value)
            ->where(function ($query) use ($vehicleId, $plate): void {
                $query
                    ->where('vehicle_id', $vehicleId)
                    ->orWhereRaw("REPLACE(REPLACE(UPPER(vehicle_plate), '-', ''), ' ', '') = ?", [$plate]);
            });

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $registers = $query->get();

        if ($registers->isEmpty()) {
            return false;
        }

        if ($registers->count() !== 1) {
            return null;
        }

        $register = $registers->sole();
        $sameId = $this->normalizer->identifier($register->vehicle_id) === $vehicleId;
        $samePlate = $this->normalizer->plate($register->vehicle_plate) === $plate;

        return $sameId && $samePlate ? $register : null;
    }

    /**
     * @param  array<string, string|null>  $canonical
     * @param  array<string, RemovalChange>  $changes
     * @return array{item: IntegrationInboxItem, old_pdf_path: ?string, old_candidate_path: ?string, discard_upload: bool}
     */
    private function persistExisting(
        IntegrationInboxItem $item,
        PreparedRemovalPdf $pdf,
        array $canonical,
        Register $register,
        ?string $uploadedPath,
    ): array {
        $changes = $this->changes($register, $canonical, $pdf);
        $shouldReadd = $register->status === RegisterStatusEnum::CANCELLED;

        if (! $this->canUpdateRegister($register) && ! $shouldReadd) {
            return $this->persistBlocked(
                $item,
                $pdf,
                $register,
                $changes,
                $uploadedPath,
            );
        }

        if ($changes === []) {
            if ($shouldReadd) {
                $register->forceFill([
                    'status' => RegisterStatusEnum::PENDING,
                ])->save();

                $item->forceFill([
                    'status' => 'alert',
                    'register_id' => $register->id,
                    'failure_reason' => null,
                    'proposed_changes' => null,
                    'alerts' => ['register_readded'],
                    'candidate_pdf_path' => null,
                    'candidate_pdf_sha256' => null,
                    'resolved_at' => null,
                ])->save();

                return [
                    'item' => $item,
                    'old_pdf_path' => null,
                    'old_candidate_path' => null,
                    'discard_upload' => false,
                ];
            }

            $oldCandidatePath = $item->candidate_pdf_path;

            $item->forceFill([
                'status' => 'no_changes',
                'register_id' => $register->id,
                'proposed_changes' => [],
                'alerts' => null,
                'candidate_pdf_path' => null,
                'candidate_pdf_sha256' => null,
                'failure_reason' => null,
                'resolved_at' => now(),
            ])->save();

            return [
                'item' => $item,
                'old_pdf_path' => null,
                'old_candidate_path' => $oldCandidatePath,
                'discard_upload' => false,
            ];
        }

        $pdfChanged = array_key_exists('pdf_path', $changes);
        $newPdfPath = $uploadedPath;

        if ($pdfChanged && $newPdfPath === null && $item->candidate_pdf_sha256 === $pdf->sha256) {
            $newPdfPath = $item->candidate_pdf_path;
        }

        if ($pdfChanged && $newPdfPath === null) {
            throw new \RuntimeException('O PDF atualizado não foi preparado antes da transação.');
        }

        $proposed = $this->registerAttributes($canonical, $pdf, $register->pdf_path);
        $proposed['notes'] = $this->mergePhoneLine(
            $this->comparableRegisterValue($register, 'notes'),
            $pdf->extractedData['origin_phones'] ?? null,
        );
        $updates = [];

        foreach (self::REGISTER_FIELDS as $field) {
            $current = $this->comparableRegisterValue($register, $field);
            $next = $proposed[$field] ?? null;

            if (! $this->normalizer->equivalent($this->normalizationField($field), $current, $next)) {
                $updates[$field] = $next;
            }
        }

        $oldPdfPath = null;

        if ($pdfChanged) {
            $oldPdfPath = $register->pdf_path !== null
                && trim($register->pdf_path) !== ''
                && $register->pdf_path !== $newPdfPath
                ? $register->pdf_path
                : null;
            $updates['pdf_path'] = $newPdfPath;
            $updates['pdf_sha256'] = $pdf->sha256;
        }

        if ($shouldReadd) {
            $updates['status'] = RegisterStatusEnum::PENDING;
        }

        $register->forceFill($updates)->save();

        $alerts = $this->alertsForUpdate($changes, $canonical['fipe_value']);

        if ($shouldReadd) {
            $alerts = array_values(array_unique([...$alerts, 'register_readded']));
        }

        $oldCandidatePath = $item->candidate_pdf_path;

        $item->forceFill([
            'status' => $alerts === [] ? 'processed' : 'alert',
            'register_id' => $register->id,
            'failure_reason' => null,
            'proposed_changes' => null,
            'alerts' => $alerts === [] ? null : $alerts,
            'candidate_pdf_path' => null,
            'candidate_pdf_sha256' => null,
            'resolved_at' => $alerts === [] ? now() : null,
        ])->save();

        return [
            'item' => $item,
            'old_pdf_path' => $oldPdfPath,
            'old_candidate_path' => $oldCandidatePath === $newPdfPath ? null : $oldCandidatePath,
            'discard_upload' => false,
        ];
    }

    /**
     * @param  array<string, string|null>  $canonical
     * @return array{item: IntegrationInboxItem, old_pdf_path: ?string, old_candidate_path: ?string, discard_upload: bool}
     */
    private function persistNew(
        IntegrationInboxItem $item,
        PreparedRemovalPdf $pdf,
        array $canonical,
        ?string $uploadedPath,
    ): array {
        if ($uploadedPath === null) {
            throw new \RuntimeException('O PDF do novo registro não foi preparado antes da transação.');
        }

        $register = Register::query()->create($this->registerAttributes($canonical, $pdf, $uploadedPath));
        $isZeroFipe = $canonical['fipe_value'] === '0.00';

        $item->forceFill([
            'status' => $isZeroFipe ? 'alert' : 'processed',
            'register_id' => $register->id,
            'failure_reason' => null,
            'proposed_changes' => null,
            'alerts' => $isZeroFipe ? ['zero_fipe'] : null,
            'candidate_pdf_path' => null,
            'candidate_pdf_sha256' => null,
            'resolved_at' => $isZeroFipe ? null : now(),
        ])->save();

        return [
            'item' => $item,
            'old_pdf_path' => null,
            'old_candidate_path' => null,
            'discard_upload' => false,
        ];
    }

    /**
     * @param  array<string, RemovalChange>  $changes
     * @return array{item: IntegrationInboxItem, old_pdf_path: ?string, old_candidate_path: ?string, discard_upload: bool}
     */
    private function persistBlocked(
        IntegrationInboxItem $item,
        PreparedRemovalPdf $pdf,
        Register $register,
        array $changes,
        ?string $uploadedPath,
    ): array {
        $pdfChanged = array_key_exists('pdf_path', $changes);
        $candidatePath = null;
        $candidateHash = null;

        if ($pdfChanged) {
            $candidatePath = $uploadedPath;

            if ($candidatePath === null && $item->candidate_pdf_sha256 === $pdf->sha256) {
                $candidatePath = $item->candidate_pdf_path;
            }

            if ($candidatePath === null) {
                throw new \RuntimeException('O PDF candidato não foi preparado antes da transação.');
            }

            $candidateHash = $pdf->sha256;
        }

        $oldCandidatePath = $item->candidate_pdf_path === $candidatePath
            ? null
            : $item->candidate_pdf_path;

        $item->forceFill([
            'status' => 'pending',
            'register_id' => $register->id,
            'proposed_changes' => $changes,
            'failure_reason' => 'update_blocked_by_status',
            'alerts' => null,
            'candidate_pdf_path' => $candidatePath,
            'candidate_pdf_sha256' => $candidateHash,
            'resolved_at' => null,
        ])->save();

        return [
            'item' => $item,
            'old_pdf_path' => null,
            'old_candidate_path' => $oldCandidatePath,
            'discard_upload' => false,
        ];
    }

    /**
     * @param  array<string, string|null>  $canonical
     * @return array<string, RemovalChange>
     */
    private function changes(Register $register, array $canonical, PreparedRemovalPdf $pdf): array
    {
        $proposed = $this->registerAttributes($canonical, $pdf, $register->pdf_path);
        $proposed['notes'] = $this->mergePhoneLine(
            $this->comparableRegisterValue($register, 'notes'),
            $pdf->extractedData['origin_phones'] ?? null,
        );
        $changes = [];

        foreach (self::REGISTER_FIELDS as $field) {
            $current = $this->comparableRegisterValue($register, $field);
            $next = $proposed[$field] ?? null;

            if (! $this->normalizer->equivalent($this->normalizationField($field), $current, $next)) {
                $changes[$field] = [
                    'current' => $this->serializeChangeValue($field, $current),
                    'proposed' => $this->serializeChangeValue($field, $next),
                ];
            }
        }

        if ($this->pdfChanged($register, $pdf)) {
            $changes['pdf_path'] = [
                'current' => [
                    'path' => $register->pdf_path,
                    'sha256' => $register->pdf_sha256,
                ],
                'proposed' => [
                    'file_name' => $pdf->fileName,
                    'sha256' => $pdf->sha256,
                ],
            ];
        }

        return $changes;
    }

    private function pdfChanged(Register $register, PreparedRemovalPdf $pdf): bool
    {
        return $register->pdf_sha256 === null
            || ! hash_equals($register->pdf_sha256, $pdf->sha256);
    }

    private function canUpdateRegister(Register $register): bool
    {
        return in_array($register->status, [
            RegisterStatusEnum::PENDING,
            RegisterStatusEnum::COLLECTED,
        ], true);
    }

    /** @param array<string, RemovalChange> $changes @return list<string> */
    private function alertsForUpdate(array $changes, ?string $fipeValue): array
    {
        $alerts = [];

        if (array_key_exists('value', $changes)) {
            $alerts[] = 'freight_changed';
        }

        if ($fipeValue === '0.00') {
            $alerts[] = 'zero_fipe';
        }

        return array_values(array_unique($alerts));
    }

    private function serializeChangeValue(string $field, mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if (in_array($field, ['deadline_withdraw', 'deadline_delivery'], true) && is_string($value)) {
            return Carbon::createFromFormat('!Y-m-d', $value)->toISOString();
        }

        if (in_array($field, ['value', 'fipe_value'], true)) {
            return $this->normalizer->decimal(is_scalar($value) ? (string) $value : null);
        }

        return $value;
    }

    private function cleanupAfterCommit(
        IntegrationInboxItem $item,
        ?string $oldPdfPath,
        ?string $oldCandidatePath,
    ): void {
        $paths = array_unique(array_filter(
            [$oldPdfPath, $oldCandidatePath],
            fn (?string $path): bool => $path !== null && trim($path) !== '',
        ));

        foreach ($paths as $path) {
            try {
                $this->storage->delete($path);
            } catch (Throwable) {
                $this->recordCleanupFailure($item, $path);
            }
        }
    }

    private function recordCleanupFailure(IntegrationInboxItem $item, string $path): void
    {
        $data = is_array($item->extracted_data) ? $item->extracted_data : [];
        $alerts = is_array($data['technical_alerts'] ?? null) ? $data['technical_alerts'] : [];
        $alerts[] = [
            'type' => 'pdf_cleanup_failed',
            'path' => $path,
        ];
        $data['technical_alerts'] = $alerts;

        try {
            $item->forceFill(['extracted_data' => $data])->save();
        } catch (Throwable) {
        }
    }

    /** @param array<string, string|null> $canonical @return array<string, mixed> */
    private function registerAttributes(array $canonical, PreparedRemovalPdf $pdf, ?string $pdfPath): array
    {
        return [
            'company' => CompanyEnum::COPART,
            'vehicle_model' => $canonical['vehicle_model'],
            'vehicle_plate' => $canonical['vehicle_plate'],
            'origin_city' => $canonical['origin_city'],
            'destination_city' => $canonical['destination_city'],
            'deadline_withdraw' => $canonical['deadline_withdraw'],
            'deadline_delivery' => $canonical['deadline_delivery'],
            'vehicle_id' => $canonical['vehicle_id'],
            'value' => $canonical['value'],
            'status' => RegisterStatusEnum::PENDING,
            'pdf_path' => $pdfPath,
            'pdf_sha256' => $pdf->sha256,
            'notes' => $this->phoneLine($pdf->extractedData['origin_phones'] ?? null),
            'insurance' => $canonical['insurance'],
            'fipe_value' => $canonical['fipe_value'],
            'payment_code' => $canonical['payment_code'],
        ];
    }

    private function normalizationField(string $field): string
    {
        return match ($field) {
            'vehicle_model', 'origin_city', 'notes' => 'text',
            'vehicle_plate' => 'plate',
            'vehicle_id', 'payment_code' => 'identifier',
            'deadline_withdraw', 'deadline_delivery' => 'date',
            'value', 'fipe_value' => 'decimal',
            default => $field,
        };
    }

    private function comparableRegisterValue(Register $register, string $field): mixed
    {
        $value = $register->getAttribute($field);

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function phoneLine(mixed $phones): ?string
    {
        if (! is_array($phones)) {
            return null;
        }

        $phones = array_values(array_filter(array_map(
            fn (mixed $phone): ?string => is_scalar($phone) ? $this->normalizer->text((string) $phone) : null,
            $phones,
        )));

        return $phones === [] ? null : 'Telefones Origem: '.implode(' / ', $phones);
    }

    private function mergePhoneLine(?string $notes, mixed $phones): ?string
    {
        $phoneLine = $this->phoneLine($phones);

        if ($phoneLine === null) {
            return $notes;
        }

        $lines = preg_split('/\R/u', (string) $notes) ?: [];

        foreach ($lines as $line) {
            if ($this->normalizer->equivalent('text', $line, $phoneLine)) {
                return $notes;
            }
        }

        return trim((string) $notes) === '' ? $phoneLine : trim((string) $notes).PHP_EOL.$phoneLine;
    }

    private function markPending(IntegrationInboxItem $item, string $failureReason): IntegrationInboxItem
    {
        $item->forceFill([
            'status' => 'pending',
            'register_id' => null,
            'failure_reason' => $failureReason,
            'proposed_changes' => null,
            'alerts' => null,
            'candidate_pdf_path' => null,
            'candidate_pdf_sha256' => null,
            'resolved_at' => null,
        ])->save();

        return $item->refresh();
    }
}
