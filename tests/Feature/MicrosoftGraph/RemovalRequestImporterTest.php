<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\RemovalRequests\PreparedRemovalPdf;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestImporter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfStorage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RemovalRequestImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_register_and_processes_the_inbox_item(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $register = Register::query()->sole();

            $this->assertSame($item->id, $result->id);
            $this->assertSame('processed', $result->status);
            $this->assertSame($register->id, $result->register_id);
            $this->assertNotNull($result->resolved_at);
            $this->assertSame('copart', $register->company->value);
            $this->assertSame('pending', $register->status->value);
            $this->assertSame('FIAT ARGO 1.3', $register->vehicle_model);
            $this->assertSame('ABC1D23', $register->vehicle_plate);
            $this->assertSame('São Paulo', $register->origin_city);
            $this->assertSame('Pirapora', $register->destination_city);
            $this->assertSame('2026-08-26', $register->deadline_withdraw->toDateString());
            $this->assertSame('2026-09-03', $register->deadline_delivery->toDateString());
            $this->assertSame('1156340', $register->vehicle_id);
            $this->assertSame('500.00', $register->value);
            $this->assertSame('43897.00', $register->fipe_value);
            $this->assertSame('ALLIANZ SEGUROS SA', $register->insurance);
            $this->assertSame('T691299', $register->payment_code);
            $this->assertSame('Telefones Origem: 11 99999 1111 / 11 98888 2222', $register->notes);
            $this->assertSame($pdf->sha256, $register->pdf_sha256);
            $this->assertNotNull($register->pdf_path);
            $this->assertTrue(Storage::disk('s3')->exists($register->pdf_path));
            $this->assertSame($pdf->extractedData, $result->extracted_data['pdf']);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_it_imports_a_register_when_withdrawal_deadline_is_after_delivery_deadline(): void
    {
        Storage::fake('s3');
        $item = $this->item(['body' => ['deadline_withdraw' => '2026-09-04']]);
        $pdf = $this->pdf(['deadline_withdraw' => '04/09/2026']);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $register = Register::query()->sole();

            $this->assertSame('processed', $result->status);
            $this->assertSame($register->id, $result->register_id);
            $this->assertSame('2026-09-04', $register->deadline_withdraw->toDateString());
            $this->assertSame('2026-09-03', $register->deadline_delivery->toDateString());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_zero_fipe_creates_an_alert_without_resolving_the_item(): void
    {
        Storage::fake('s3');
        $item = $this->item(['body' => ['fipe_value' => '0,00']]);
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('alert', $result->status);
            $this->assertSame(['zero_fipe'], $result->alerts);
            $this->assertNull($result->resolved_at);
            $this->assertDatabaseCount('registers', 1);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('invalidImportProvider')]
    public function test_invalid_imports_are_pending_without_register_or_upload(
        array $changes,
        string $failureReason,
    ): void {
        Storage::fake('s3');
        $item = $this->item($changes);
        $pdf = $this->pdf($changes['pdf'] ?? []);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('pending', $result->status);
            $this->assertSame($failureReason, $result->failure_reason);
            $this->assertNull($result->register_id);
            $this->assertNull($result->resolved_at);
            $this->assertDatabaseCount('registers', 0);
            $this->assertSame($pdf->extractedData, $result->extracted_data['pdf']);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function invalidImportProvider(): array
    {
        return [
            'missing body vehicle id' => [['body' => ['vehicle_id' => null]], 'missing_body_fields'],
            'missing pdf model' => [['pdf' => ['vehicle_model' => null]], 'missing_pdf_fields'],
            'plate conflict' => [['pdf' => ['vehicle_plate' => 'ZZZ9999']], 'vehicle_plate_mismatch'],
            'body id conflict' => [['body' => ['vehicle_id' => '9999999']], 'vehicle_id_mismatch'],
            'insurance conflict' => [['pdf' => ['insurance' => 'TOKIO MARINE']], 'insurance_mismatch'],
            'destination conflict' => [['pdf' => ['destination_city' => 'Campinas']], 'destination_city_mismatch'],
            'deadline conflict' => [['pdf' => ['deadline_delivery' => '04/09/2026']], 'deadline_delivery_mismatch'],
            'invalid model length' => [['pdf' => ['vehicle_model' => str_repeat('X', 31)]], 'invalid_constraints'],
            'invalid money' => [['body' => ['value' => 'not-money']], 'invalid_constraints'],
            'value exceeds database precision' => [['body' => ['value' => '10000.00']], 'invalid_constraints'],
            'fipe exceeds database precision' => [['body' => ['fipe_value' => '1000000.00']], 'invalid_constraints'],
            'invalid body date' => [['body' => ['deadline_delivery' => '31/02/2026']], 'invalid_constraints'],
            'invalid pdf date' => [['pdf' => ['deadline_delivery' => '31/02/2026']], 'invalid_constraints'],
        ];
    }

    #[DataProvider('moneyBoundaryProvider')]
    public function test_money_values_at_database_limits_are_imported(string $value, string $fipeValue): void
    {
        Storage::fake('s3');
        $item = $this->item(['body' => [
            'value' => $value,
            'fipe_value' => $fipeValue,
        ]]);
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
            $this->assertSame($value, Register::query()->sole()->value);
            $this->assertSame($fipeValue, Register::query()->sole()->fipe_value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function moneyBoundaryProvider(): array
    {
        return [
            'value maximum' => ['9999.99', '43897.00'],
            'fipe maximum' => ['500.00', '999999.99'],
        ];
    }

    public function test_it_rejects_partial_or_ambiguous_identity_without_mutation(): void
    {
        Storage::fake('s3');
        $first = Register::factory()->create(['vehicle_id' => '1156340', 'vehicle_plate' => 'OTHER01']);
        Register::factory()->create(['vehicle_id' => '999999', 'vehicle_plate' => 'ABC1D23']);
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('identity_conflict', $result->failure_reason);
            $this->assertNull($result->register_id);
            $this->assertSame($first->vehicle_model, $first->refresh()->vehicle_model);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_same_hash_and_no_changes_is_a_no_op_without_upload(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'pdf_sha256' => $pdf->sha256,
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('no_changes', $result->status, (string) json_encode($result->proposed_changes));
            $this->assertSame($register->id, $result->register_id);
            $this->assertNotNull($result->resolved_at);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('pdfDifferenceProvider')]
    public function test_pending_register_pdf_difference_is_applied_and_old_pdf_is_deleted(?string $currentHash): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/old/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-old');
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
            'pdf_path' => $oldPath,
            'pdf_sha256' => $currentHash,
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
            $this->assertNull($result->failure_reason);
            $this->assertSame($register->id, $result->register_id);
            $this->assertNull($result->proposed_changes);
            $this->assertSame($pdf->sha256, $register->refresh()->pdf_sha256);
            $this->assertNotSame($oldPath, $register->pdf_path);
            $this->assertFalse(Storage::disk('s3')->exists($oldPath));
            $this->assertTrue(Storage::disk('s3')->exists($register->pdf_path));
            $activity = Activity::query()
                ->where('subject_type', Register::class)
                ->where('subject_id', $register->id)
                ->latest('id')
                ->firstOrFail();
            $attributes = $activity->properties->toArray()['attributes'];
            $this->assertArrayHasKey('pdf_path', $attributes);
            $this->assertArrayHasKey('pdf_sha256', $attributes);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_collected_register_pdf_difference_is_applied_and_old_pdf_is_deleted(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/old/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-old');
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'status' => 'collected',
            'pdf_path' => $oldPath,
            'pdf_sha256' => str_repeat('a', 64),
        ]);

        try {
            $result = app(RemovalRequestImporter::class)->handle($this->item(), $pdf);

            $this->assertSame('processed', $result->status);
            $this->assertSame('collected', $register->refresh()->status->value);
            $this->assertFalse(Storage::disk('s3')->exists($oldPath));
            $this->assertTrue(Storage::disk('s3')->exists($register->pdf_path));
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function pdfDifferenceProvider(): array
    {
        return [
            'different hash' => [str_repeat('a', 64)],
            'missing hash' => [null],
        ];
    }

    public function test_it_preserves_manual_notes_and_does_not_duplicate_phone_line(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $pdf = $this->pdf(['origin_phones' => ['11 99999 1111']]);
            app(RemovalRequestImporter::class)->handle($item, $pdf);

            $register = Register::query()->sole();
            $this->assertSame('Telefones Origem: 11 99999 1111', $register->notes);
            $register->update(['notes' => "Manual note\nTelefones Origem: 11 99999 1111"]);
            $this->assertSame("Manual note\nTelefones Origem: 11 99999 1111", $register->refresh()->notes);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_existing_manual_notes_are_preserved_when_task7_updates_the_register(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'notes' => 'Manual note',
            'value' => '500.00',
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
            $this->assertNull($result->failure_reason);
            $this->assertSame(
                "Manual note\nTelefones Origem: 11 99999 1111 / 11 98888 2222",
                $register->refresh()->notes,
            );
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('importableFieldProvider')]
    public function test_pending_and_collected_registers_apply_each_importable_field(
        string $status,
        string $field,
        array $itemChanges,
        array $pdfChanges,
        string $expected,
    ): void {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/old/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-old');
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'notes' => 'Manual note',
            'status' => $status,
            'driver' => 'Driver stays',
            'collected_date' => '2026-08-27',
            'delivery_confirmed_at' => '2026-08-28 10:00:00',
            'payment_deferred_at' => '2026-08-29 10:00:00',
            'pdf_path' => $oldPath,
            'pdf_sha256' => $pdf->sha256,
        ]);
        $item = $this->item($itemChanges);
        $changedPdf = $this->pdf($pdfChanges);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $changedPdf);

            $register = $register->refresh();
            $this->assertSame($field === 'value' ? 'alert' : 'processed', $result->status);
            $this->assertSame($expected, $field === 'deadline_withdraw' || $field === 'deadline_delivery'
                ? $register->{$field}->toDateString()
                : $register->{$field});
            $this->assertSame($status, $register->status->value);
            $this->assertSame('Driver stays', $register->driver);
            $this->assertSame('2026-08-27', $register->collected_date->toDateString());
            $this->assertSame('2026-08-28 10:00:00', $register->delivery_confirmed_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-08-29 10:00:00', $register->payment_deferred_at->format('Y-m-d H:i:s'));
        } finally {
            @unlink($pdf->temporaryPath);
            @unlink($changedPdf->temporaryPath);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, array<string, mixed>>, 3: array<string, mixed>, 4: string}> */
    public static function importableFieldProvider(): array
    {
        $fields = [
            'vehicle_model' => ['vehicle_model', [], ['vehicle_model' => 'FIAT ARGO 1.4'], 'FIAT ARGO 1.4'],
            'origin_city' => ['origin_city', [], ['origin_city' => 'Campinas'], 'Campinas'],
            'destination_city' => ['destination_city', ['body' => ['destination_city' => 'Jundiaí']], ['destination_city' => 'Jundiaí - SP'], 'Jundiaí'],
            'deadline_withdraw' => ['deadline_withdraw', ['body' => ['deadline_withdraw' => '28/08/2026']], ['deadline_withdraw' => '28/08/2026'], '2026-08-28'],
            'deadline_delivery' => ['deadline_delivery', ['body' => ['deadline_delivery' => '05/09/2026']], ['deadline_delivery' => '05/09/2026'], '2026-09-05'],
            'value' => ['value', ['body' => ['value' => 'R$ 600,00']], [], '600.00'],
            'insurance' => ['insurance', ['subject' => ['insurance' => 'TOKIO MARINE'], 'body' => ['insurance' => 'TOKIO MARINE']], ['insurance' => 'TOKIO MARINE'], 'TOKIO MARINE'],
            'fipe_value' => ['fipe_value', ['body' => ['fipe_value' => 'R$ 40.000,00']], [], '40000.00'],
            'payment_code' => ['payment_code', ['body' => ['payment_code' => 'P123']], [], 'P123'],
            'notes' => ['notes', [], ['origin_phones' => ['11 97777 0000']], "Manual note\nTelefones Origem: 11 97777 0000"],
        ];

        $cases = [];

        foreach (['pending', 'collected'] as $status) {
            foreach ($fields as $name => [$field, $itemChanges, $pdfChanges, $expected]) {
                $cases[$status.' '.$name] = [$status, $field, $itemChanges, $pdfChanges, $expected];
            }
        }

        return $cases;
    }

    public function test_same_pdf_hash_with_field_changes_does_not_upload(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'pdf_sha256' => $pdf->sha256,
            'pdf_path' => 'registros/copart/1156340/current/CartaDeRemoção ABC1D23.pdf',
        ]);
        $item = $this->item(['body' => [
            'value' => 'R$ 600,00',
            'deadline_delivery' => '05/09/2026',
        ]]);
        $changedPdf = $this->pdf(['deadline_delivery' => '05/09/2026']);
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldNotReceive('store');
        $storage->shouldNotReceive('delete');

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $changedPdf);

            $this->assertSame('alert', $result->status);
            $this->assertSame('600.00', $register->refresh()->value);
        } finally {
            @unlink($pdf->temporaryPath);
            @unlink($changedPdf->temporaryPath);
        }
    }

    #[DataProvider('blockedStatusProvider')]
    public function test_non_operational_statuses_keep_register_unchanged_and_store_a_candidate(string $status): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/current/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-current');
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'deadline_delivery' => '2026-09-03',
            'pdf_path' => $oldPath,
            'pdf_sha256' => str_repeat('a', 64),
            'status' => $status,
        ]);
        $item = $this->item(['body' => [
            'value' => 'R$ 600,00',
            'deadline_delivery' => '05/09/2026',
        ]]);
        $changedPdf = $this->pdf(['deadline_delivery' => '05/09/2026']);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $changedPdf);

            $this->assertSame('pending', $result->status);
            $this->assertSame('update_blocked_by_status', $result->failure_reason);
            $this->assertNull($result->resolved_at);
            $this->assertSame('500.00', $register->refresh()->value);
            $this->assertSame($status, $register->status->value);
            $this->assertSame($oldPath, $register->pdf_path);
            $this->assertSame('500.00', $result->proposed_changes['value']['current']);
            $this->assertSame('600.00', $result->proposed_changes['value']['proposed']);
            $this->assertSame($changedPdf->fileName, $result->proposed_changes['pdf_path']['proposed']['file_name']);
            $this->assertSame('2026-09-03T00:00:00.000000Z', $result->proposed_changes['deadline_delivery']['current']);
            $this->assertSame('2026-09-05T00:00:00.000000Z', $result->proposed_changes['deadline_delivery']['proposed']);
            $this->assertNotNull($result->candidate_pdf_path);
            $this->assertSame($changedPdf->sha256, $result->candidate_pdf_sha256);
            $this->assertTrue(Storage::disk('s3')->exists($result->candidate_pdf_path));
            $this->assertTrue(Storage::disk('s3')->exists($oldPath));
        } finally {
            @unlink($pdf->temporaryPath);
            @unlink($changedPdf->temporaryPath);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function blockedStatusProvider(): array
    {
        return [
            'paid' => ['paid'],
            'delivered' => ['delivered'],
            'available' => ['available'],
            'pending daily rates' => ['pending daily rates'],
            'invoiced' => ['invoiced'],
        ];
    }

    public function test_a_cancelled_register_with_changes_is_readded_and_returns_to_pending(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'status' => 'cancelled',
        ]);
        $item = $this->item(['body' => ['value' => 'R$ 600,00']]);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('alert', $result->status);
            $this->assertContains('register_readded', $result->alerts ?? []);
            $this->assertContains('freight_changed', $result->alerts ?? []);
            $this->assertNull($result->resolved_at);
            $this->assertSame($register->id, $result->register_id);
            $this->assertSame('pending', $register->refresh()->status->value);
            $this->assertSame('600.00', $register->value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_a_cancelled_register_with_identical_content_is_still_readded(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
            'pdf_sha256' => $pdf->sha256,
            'status' => 'cancelled',
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('alert', $result->status);
            $this->assertSame(['register_readded'], $result->alerts);
            $this->assertNull($result->resolved_at);
            $this->assertSame($register->id, $result->register_id);
            $this->assertSame('pending', $register->refresh()->status->value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_blocked_same_pdf_hash_has_no_candidate(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'status' => 'paid',
            'pdf_sha256' => $pdf->sha256,
            'pdf_path' => 'registros/copart/1156340/current/CartaDeRemoção ABC1D23.pdf',
        ]);
        $item = $this->item(['body' => ['value' => 'R$ 600,00']]);
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldNotReceive('store');
        $storage->shouldNotReceive('delete');

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('pending', $result->status);
            $this->assertNull($result->candidate_pdf_path);
            $this->assertNull($result->candidate_pdf_sha256);
            $this->assertSame('500.00', $register->refresh()->value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_reprocessing_a_blocked_request_reuses_the_existing_candidate(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'status' => 'paid',
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();

        try {
            $first = app(RemovalRequestImporter::class)->handle($item, $pdf);
            $candidatePath = $first->candidate_pdf_path;
            $this->assertNotNull($candidatePath);
            $this->assertCount(1, Storage::disk('s3')->allFiles());

            $second = app(RemovalRequestImporter::class)->handle($first->refresh(), $pdf);

            $this->assertSame($candidatePath, $second->candidate_pdf_path);
            $this->assertCount(1, Storage::disk('s3')->allFiles());
            $this->assertSame($register->id, $second->register_id);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_zero_fipe_after_a_real_update_creates_an_alert(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'fipe_value' => '43897.00',
            'value' => '500.00',
            'pdf_sha256' => $pdf->sha256,
        ]);
        $item = $this->item(['body' => ['fipe_value' => 'R$ 0,00']]);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('alert', $result->status);
            $this->assertSame(['zero_fipe'], $result->alerts);
            $this->assertNull($result->resolved_at);
            $this->assertSame('0.00', $register->refresh()->fipe_value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_freight_and_zero_fipe_alerts_are_deterministic_and_unique(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'fipe_value' => '43897.00',
            'pdf_sha256' => $pdf->sha256,
        ]);
        $item = $this->item(['body' => [
            'value' => 'R$ 600,00',
            'fipe_value' => 'R$ 0,00',
        ]]);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame(['freight_changed', 'zero_fipe'], $result->alerts);
            $this->assertSame(['freight_changed', 'zero_fipe'], array_values(array_unique($result->alerts)));
            $this->assertNull($result->resolved_at);
            $this->assertSame('600.00', $register->refresh()->value);
            $this->assertSame('0.00', $register->fipe_value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_replaying_a_noop_zero_fipe_register_does_not_create_an_alert(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'fipe_value' => '0.00',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'payment_code' => 'T691299',
            'pdf_sha256' => $pdf->sha256,
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
        ]);
        $item = $this->item(['body' => ['fipe_value' => 'R$ 0,00']]);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('no_changes', $result->status);
            $this->assertNull($result->alerts);
            $this->assertNotNull($result->resolved_at);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_old_pdf_delete_failure_keeps_the_new_pdf_and_records_a_safe_cleanup_alert(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/old/CartaDeRemoção ABC1D23.pdf';
        $newPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'pdf_path' => $oldPath,
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($newPath);
        $storage->shouldReceive('delete')->once()->with($oldPath)->andThrow(new RuntimeException('old PDF unavailable'));

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame($newPath, $register->refresh()->pdf_path);
            $this->assertSame($pdf->sha256, $register->pdf_sha256);
            $this->assertSame([
                [
                    'type' => 'pdf_cleanup_failed',
                    'path' => $oldPath,
                ],
            ], $result->extracted_data['technical_alerts']);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_blocked_candidate_persist_failure_deletes_only_the_candidate(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/current/CartaDeRemoção ABC1D23.pdf';
        $candidatePath = 'registros/copart/1156340/candidate/CartaDeRemoção ABC1D23.pdf';
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'status' => 'paid',
            'pdf_path' => $oldPath,
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($candidatePath);
        $storage->shouldReceive('delete')->once()->with($candidatePath);
        Event::listen('eloquent.updating: '.IntegrationInboxItem::class, function (): void {
            throw new RuntimeException('inbox update failed');
        });

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertSame('queued', $item->refresh()->status);
            $this->assertNull($item->candidate_pdf_path);
            $this->assertSame($oldPath, $register->refresh()->pdf_path);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_database_failure_during_existing_update_deletes_new_pdf_and_preserves_old_register(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldPath = 'registros/copart/1156340/current/CartaDeRemoção ABC1D23.pdf';
        $newPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-current');
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'value' => '500.00',
            'pdf_path' => $oldPath,
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($newPath);
        $storage->shouldReceive('delete')->once()->with($newPath);
        Event::listen('eloquent.updating: '.Register::class, function (): void {
            throw new RuntimeException('register update failed');
        });

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertSame($oldPath, $register->refresh()->pdf_path);
            $this->assertSame(str_repeat('a', 64), $register->pdf_sha256);
            $this->assertSame('queued', $item->refresh()->status);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_database_failure_while_replacing_candidate_preserves_previous_candidate(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $oldCandidatePath = 'registros/copart/1156340/candidate/old.pdf';
        $newCandidatePath = 'registros/copart/1156340/candidate/new/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldCandidatePath, '%PDF-candidate');
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'status' => 'paid',
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item([
            'register_id' => $register->id,
        ]);
        $item->forceFill([
            'register_id' => $register->id,
            'candidate_pdf_path' => $oldCandidatePath,
            'candidate_pdf_sha256' => str_repeat('b', 64),
        ])->save();
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldReceive('store')->once()->andReturn($newCandidatePath);
        $storage->shouldReceive('delete')->once()->with($newCandidatePath);
        Event::listen('eloquent.updating: '.IntegrationInboxItem::class, function (): void {
            throw new RuntimeException('candidate update failed');
        });

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertSame($oldCandidatePath, $item->refresh()->candidate_pdf_path);
            $this->assertTrue(Storage::disk('s3')->exists($oldCandidatePath));
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_storage_failure_before_transaction_does_not_mutate_register_or_inbox(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $storage = $this->mock(RemovalRequestPdfStorage::class);
        $storage->shouldReceive('store')->once()->andThrow(new RuntimeException('S3 unavailable'));

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertDatabaseCount('registers', 0);
            $this->assertSame('queued', $item->refresh()->status);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_changed_imported_fields_are_written_to_register_activity_log(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'destination_city' => 'Pirapora',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'pdf_sha256' => $pdf->sha256,
        ]);
        $item = $this->item([
            'body' => [
                'destination_city' => 'Jundiaí',
                'deadline_delivery' => '05/09/2026',
                'value' => 'R$ 600,00',
                'fipe_value' => 'R$ 40.000,00',
                'payment_code' => 'P123',
                'insurance' => 'TOKIO MARINE',
            ],
            'subject' => ['insurance' => 'TOKIO MARINE'],
        ]);
        $changedPdf = $this->pdf([
            'destination_city' => 'Jundiaí - SP',
            'deadline_delivery' => '05/09/2026',
            'insurance' => 'TOKIO MARINE',
        ]);

        try {
            app(RemovalRequestImporter::class)->handle($item, $changedPdf);

            $activity = Activity::query()
                ->where('subject_type', Register::class)
                ->where('subject_id', $register->id)
                ->latest('id')
                ->firstOrFail();
            $properties = $activity->properties->toArray();

            foreach (['destination_city', 'deadline_delivery', 'value', 'insurance', 'fipe_value', 'payment_code'] as $field) {
                $this->assertArrayHasKey($field, $properties['attributes']);
            }
        } finally {
            @unlink($pdf->temporaryPath);
            @unlink($changedPdf->temporaryPath);
        }
    }

    public function test_a_database_failure_after_upload_deletes_the_new_pdf(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $uploadedPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        $this->mock(RemovalRequestPdfStorage::class, function (MockInterface $mock) use ($uploadedPath): void {
            $mock->shouldReceive('store')->once()->andReturn($uploadedPath);
            $mock->shouldReceive('delete')->once()->with($uploadedPath);
        });
        Event::listen('eloquent.creating: '.Register::class, function (): void {
            throw new \RuntimeException('database failure');
        });

        try {
            $this->expectException(\RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertDatabaseCount('registers', 0);
            $this->assertDatabaseHas('integration_inbox_items', [
                'id' => $item->id,
                'status' => 'queued',
            ]);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_cleanup_failure_reports_cleanup_and_preserves_the_database_error(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $uploadedPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        $this->mock(RemovalRequestPdfStorage::class, function (MockInterface $mock) use ($uploadedPath): void {
            $mock->shouldReceive('store')->once()->andReturn($uploadedPath);
            $mock->shouldReceive('delete')->once()->with($uploadedPath)->andThrow(new RuntimeException('cleanup failed'));
        });
        Event::listen('eloquent.creating: '.Register::class, function (): void {
            throw new RuntimeException('database failure');
        });

        try {
            app(RemovalRequestImporter::class)->handle($item, $pdf);
            $this->fail('The database failure should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cleanup failed', $exception->getMessage());
            $this->assertSame('database failure', $exception->getPrevious()?->getMessage());
        } finally {
            $this->assertDatabaseCount('registers', 0);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_it_uses_the_normalized_plate_in_the_import_lock_key(): void
    {
        Storage::fake('s3');
        $item = $this->item(['subject' => ['vehicle_plate' => 'ABC-1D23']]);
        $pdf = $this->pdf(['vehicle_plate' => 'ABC 1D23']);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($lock);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_an_occupied_plate_lock_fails_without_creating_a_duplicate(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($lock);

        try {
            $this->expectException(LockTimeoutException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertDatabaseCount('registers', 0);
            $this->assertSame('queued', $item->refresh()->status);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_different_plates_use_independent_import_locks(): void
    {
        Storage::fake('s3');
        $firstItem = $this->item();
        $firstPdf = $this->pdf();
        $secondItem = $this->item([
            'subject' => ['vehicle_plate' => 'XYZ-9876', 'vehicle_id' => '2222222'],
            'body' => ['vehicle_id' => '2222222'],
        ]);
        $secondPdf = $this->pdf([
            'vehicle_plate' => 'XYZ 9876',
            'vehicle_id' => '2222222',
        ]);
        $firstLock = Mockery::mock(Lock::class);
        $firstLock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        $secondLock = Mockery::mock(Lock::class);
        $secondLock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($firstLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:XYZ9876', 600)
            ->andReturn($secondLock);

        try {
            app(RemovalRequestImporter::class)->handle($firstItem, $firstPdf);
            app(RemovalRequestImporter::class)->handle($secondItem, $secondPdf);

            $this->assertDatabaseCount('registers', 2);
        } finally {
            @unlink($firstPdf->temporaryPath);
            @unlink($secondPdf->temporaryPath);
        }
    }

    private function item(array $overrides = []): IntegrationInboxItem
    {
        $data = [
            'subject' => [
                'vehicle_plate' => 'ABC1D23',
                'vehicle_id' => '1156340',
                'insurance' => 'ALLIANZ SEGUROS SA',
            ],
            'body' => [
                'vehicle_id' => '1156340',
                'insurance' => 'ALLIANZ SEGUROS S/A',
                'destination_city' => 'Pirapora',
                'deadline_withdraw' => '26/08/2026',
                'deadline_delivery' => '03/09/2026',
                'value' => 'R$ 500,00',
                'fipe_value' => 'R$ 43.897,00',
                'payment_code' => 'T691299',
            ],
            'body_missing_fields' => [],
        ];

        foreach (['subject', 'body'] as $source) {
            if (isset($overrides[$source])) {
                $data[$source] = array_replace($data[$source], $overrides[$source]);
            }
        }

        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
            'extracted_vehicle_id' => '1156340',
            'extracted_vehicle_plate' => 'ABC1D23',
            'extracted_data' => $data,
        ]);

        return $item;
    }

    private function pdf(array $overrides = []): PreparedRemovalPdf
    {
        $path = tempnam(sys_get_temp_dir(), 'removal_import_');
        $this->assertIsString($path);
        file_put_contents($path, '%PDF-import');

        $extractedData = array_replace([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora - SP',
            'deadline_withdraw' => '26/08/2026',
            'deadline_delivery' => '03/09/2026',
            'vehicle_id' => '1156340',
            'insurance' => 'ALLIANZ SEGUROS S/A',
            'origin_phones' => ['11 99999 1111', '11 98888 2222'],
        ], $overrides);

        return new PreparedRemovalPdf(
            temporaryPath: $path,
            sha256: hash('sha256', '%PDF-import'),
            fileName: 'CartaDeRemoção ABC1D23.pdf',
            extractedData: $extractedData,
        );
    }
}
