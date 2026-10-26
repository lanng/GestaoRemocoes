<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResolveRemovalRequestImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_applies_selected_fields_and_replaces_the_candidate_pdf(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $register = Register::factory()->create([
            'destination_city' => 'Pirapora',
            'value' => '500.00',
            'pdf_path' => 'current/CartaDeRemoção ABC1D23.pdf',
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $oldPath = $register->pdf_path;
        $candidatePath = 'candidate/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($oldPath, '%PDF-old');
        Storage::disk('s3')->put($candidatePath, '%PDF-new');
        $item = $this->pendingItem($register, [
            'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Jundiaí'],
            'value' => ['current' => '500.00', 'proposed' => '600.00'],
        ], $candidatePath);

        $result = app(ResolveRemovalRequestImport::class)->apply(
            $item,
            $user,
            ['destination_city'],
            true,
        );

        $register->refresh();
        $this->assertSame('Jundiaí', $register->destination_city);
        $this->assertSame('500.00', $register->value);
        $this->assertSame($candidatePath, $register->pdf_path);
        $this->assertSame('processed', $result->status);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertNull($result->candidate_pdf_path);
        $this->assertFalse(Storage::disk('s3')->exists($oldPath));
        $this->assertTrue(Storage::disk('s3')->exists($candidatePath));
    }

    public function test_it_can_apply_a_field_without_replacing_the_candidate_pdf(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $register = Register::factory()->create(['destination_city' => 'Pirapora']);
        $candidatePath = 'candidate/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($candidatePath, '%PDF-candidate');
        $item = $this->pendingItem($register, [
            'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Jundiaí'],
        ], $candidatePath);

        $result = app(ResolveRemovalRequestImport::class)->apply(
            $item,
            $user,
            ['destination_city'],
            false,
        );

        $this->assertSame('Jundiaí', $register->refresh()->destination_city);
        $this->assertSame('processed', $result->status);
        $this->assertFalse(Storage::disk('s3')->exists($candidatePath));
    }

    public function test_it_applies_a_withdrawal_deadline_after_the_delivery_deadline(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $register = Register::factory()->create([
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
        ]);
        $candidatePath = 'candidate/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($candidatePath, '%PDF-candidate');
        $item = $this->pendingItem($register, [
            'deadline_withdraw' => ['current' => '2026-08-26', 'proposed' => '2026-09-04'],
        ], $candidatePath);

        $result = app(ResolveRemovalRequestImport::class)->apply(
            $item,
            $user,
            ['deadline_withdraw'],
            false,
        );

        $this->assertSame('2026-09-04', $register->refresh()->deadline_withdraw->toDateString());
        $this->assertSame('2026-09-03', $register->deadline_delivery->toDateString());
        $this->assertSame('processed', $result->status);
    }

    public function test_it_rejects_a_pending_import_and_deletes_its_candidate(): void
    {
        Storage::fake('s3');
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $candidatePath = 'candidate/CartaDeRemoção ABC1D23.pdf';
        Storage::disk('s3')->put($candidatePath, '%PDF-candidate');
        $item = $this->pendingItem($register, [], $candidatePath);

        $result = app(ResolveRemovalRequestImport::class)->reject($item, $user, 'Dados incorretos');

        $this->assertSame('rejected', $result->status);
        $this->assertSame('Dados incorretos', $result->failure_reason);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertFalse(Storage::disk('s3')->exists($candidatePath));
    }

    public function test_it_acknowledges_an_alert_without_changing_the_register(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['value' => '600.00']);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['freight_changed'],
        ]);

        $result = app(ResolveRemovalRequestImport::class)->acknowledge($item, $user);

        $this->assertSame('processed', $result->status);
        $this->assertSame('600.00', $register->refresh()->value);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertNotNull($result->resolved_at);
    }

    public function test_it_rejects_review_for_non_pending_or_wrong_message_type(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'checklist',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);

        $this->expectException(DomainException::class);
        app(ResolveRemovalRequestImport::class)->apply($item, $user, [], false);
    }

    public function test_it_does_not_resolve_a_pending_import_without_a_selection(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $item = $this->pendingItem($register, [
            'destination_city' => ['current' => 'Pirapora', 'proposed' => 'Jundiaí'],
        ], 'candidate/CartaDeRemoção ABC1D23.pdf');

        $this->expectException(DomainException::class);
        app(ResolveRemovalRequestImport::class)->apply($item, $user, [], false);
    }

    /** @param array<string, array{current: mixed, proposed: mixed}> $changes */
    private function pendingItem(Register $register, array $changes, string $candidatePath): IntegrationInboxItem
    {
        return IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
            'proposed_changes' => $changes,
            'candidate_pdf_path' => $candidatePath,
            'candidate_pdf_sha256' => str_repeat('b', 64),
        ]);
    }
}
