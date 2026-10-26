<?php

namespace Tests\Feature\Services;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\CreateCteEmissionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateCteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_draft_batch_with_one_snapshot_per_register(): void
    {
        $user = User::factory()->create();
        $registers = Register::factory()->count(2)->create();

        $batch = app(CreateCteEmissionBatch::class)->handle($registers, $user, 'dry_run');

        $this->assertInstanceOf(CteEmissionBatch::class, $batch);
        $this->assertSame('draft', $batch->status->value);
        $this->assertSame('dry_run', $batch->execution_mode);
        $this->assertCount(2, $batch->documents);
        $this->assertTrue($batch->documents->every(
            fn ($document): bool => $document->status === CteDocumentStatusEnum::DRAFT
        ));
        $this->assertSame('T691299', $batch->documents->first()->snapshot['payment_code']);
    }

    public function test_it_creates_ctes_for_the_internal_emitter_as_controlled_workflow_documents(): void
    {
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(1)->create(),
            User::factory()->create(),
            'live',
        );

        $this->assertSame('controlled_v1', $batch->documents->first()->getRawOriginal('workflow_origin'));
    }

    public function test_it_rejects_a_register_that_already_has_an_authorized_cte(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
            'execution_mode' => 'live',
        ]);
        $register->cteDocuments()->create([
            'public_id' => fake()->uuid(),
            'cte_emission_batch_id' => $batch->id,
            'status' => 'authorized',
            'snapshot' => [],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => 'live',
            'emitter' => 'lab',
            'workflow_origin' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCteEmissionBatch::class)->handle(collect([$register]), $user, 'dry_run');
    }

    public function test_it_creates_a_replacement_document_when_the_reemission_is_confirmed(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['status' => 'analysis']);
        $oldBatch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        $oldDocument = CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_emission_batch_id' => $oldBatch->id,
            'cte_number' => '900100',
        ]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$register]),
            $user,
            'live',
            reemissionConfirmed: true,
            reemissionReason: 'Recusado pelo contratante',
        );

        $document = $batch->documents->first();
        $this->assertSame($oldDocument->id, $document->replaced_document_id);
        $this->assertSame('Recusado pelo contratante', $document->replacement_reason);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $oldDocument->refresh()->status);
        $this->assertSame('analysis', $register->refresh()->status->value);
    }

    public function test_it_links_only_the_registers_that_have_an_authorized_cte(): void
    {
        $user = User::factory()->create();
        $emitted = Register::factory()->create();
        $fresh = Register::factory()->create();
        $oldDocument = CteDocument::factory()->authorized()->create(['register_id' => $emitted->id]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$emitted, $fresh]),
            $user,
            'dry_run',
            reemissionConfirmed: true,
        );

        $documents = $batch->documents->keyBy('register_id');
        $this->assertSame($oldDocument->id, $documents[$emitted->id]->replaced_document_id);
        $this->assertNull($documents[$fresh->id]->replaced_document_id);
        $this->assertNull($documents[$fresh->id]->replacement_reason);
    }

    public function test_it_reopens_a_completed_batch_and_adds_only_a_new_draft(): void
    {
        $user = User::factory()->create();
        $oldRegister = Register::factory()->create();
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
            'approved_by' => $user->id,
            'approved_at' => now()->subDay(),
            'completed_at' => now()->subHour(),
        ]);
        $oldDocument = CteDocument::factory()->authorized()->create([
            'register_id' => $oldRegister->id,
            'cte_emission_batch_id' => $batch->id,
            'cte_number' => '900100',
            'access_key' => str_repeat('1', 44),
            'protocol' => '935260000000001',
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);
        $oldSnapshot = $oldDocument->snapshot;
        $newRegister = Register::factory()->create();

        $updated = app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([$newRegister]));

        $newDocument = $updated->documents->firstWhere('register_id', $newRegister->id);
        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $updated->status);
        $this->assertNull($updated->approved_at);
        $this->assertNull($updated->completed_at);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $oldDocument->refresh()->status);
        $this->assertSame($oldSnapshot, $oldDocument->snapshot);
        $this->assertSame('900100', $oldDocument->cte_number);
        $this->assertSame(str_repeat('1', 44), $oldDocument->access_key);
        $this->assertSame('935260000000001', $oldDocument->protocol);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $newDocument->status);
        $this->assertCount(2, $updated->documents);
    }

    public function test_it_reopens_a_completed_with_errors_batch_for_new_drafts(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'execution_mode' => 'dry_run',
            'completed_at' => now()->subHour(),
        ]);
        $register = Register::factory()->create();

        $updated = app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([$register]));

        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $updated->status);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $updated->documents->first()->status);
        $this->assertSame('dry_run', $updated->documents->first()->execution_mode);
        $this->assertNull($updated->completed_at);
    }

    public function test_it_blocks_inclusion_for_ciots_that_are_valid_in_progress_or_have_a_stale_draft(): void
    {
        foreach ([
            CiotStatusEnum::ISSUED,
            CiotStatusEnum::CLOSED,
            CiotStatusEnum::PENDING,
            CiotStatusEnum::DRAFT,
            CiotStatusEnum::FAILED,
        ] as $status) {
            $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
            Ciot::factory()->create(['cte_emission_batch_id' => $batch->id, 'status' => $status]);

            try {
                app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
                $this->fail("CIOT {$status->value} should block inclusion.");
            } catch (ValidationException) {
                $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
                $this->assertCount(0, $batch->documents()->get());
            }
        }
    }

    public function test_it_blocks_mdfes_by_batch_and_cte_pivot_but_allows_a_confirmed_cancellation(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);

        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('An authorized fiscal result blocks inclusion even if the status field diverged.');
        } catch (ValidationException) {
            $this->assertCount(0, $batch->documents()->get());
        }

        $closedBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $closedBatch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($closedBatch, collect([Register::factory()->create()]));
            $this->fail('A confirmed MDF-e closure blocks inclusion even if the status field diverged.');
        } catch (ValidationException) {
            $this->assertCount(0, $closedBatch->documents()->get());
        }

        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(collect([Register::factory()->create()]), $user, 'live');
        $document = $batch->documents->first();
        $document->update(['status' => CteDocumentStatusEnum::AUTHORIZED]);
        $batch->update(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);
        $mdfe->cteDocuments()->attach($document);

        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('An authorized MDF-e linked through the CT-e pivot blocks inclusion.');
        } catch (ValidationException) {
            $this->assertCount(1, $batch->documents()->get());
        }

        $mdfe->update(['fiscal_status' => FiscalDocumentStatusEnum::CANCELLED, 'fiscal_status_code' => '101']);
        $updated = app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $updated->status);
    }

    public function test_it_rejects_duplicates_missing_registers_invalid_registers_and_active_ctes_atomically(): void
    {
        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(collect([Register::factory()->create()]), $user, 'live');
        $register = Register::factory()->create();
        $batch->update(['status' => CteEmissionBatchStatusEnum::COMPLETED]);

        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([$register, $register]));
            $this->fail('Duplicate selections should be rejected.');
        } catch (ValidationException) {
            $this->assertCount(1, $batch->documents()->get());
        }

        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::make()]));
            $this->fail('Missing records should be rejected.');
        } catch (ValidationException) {
            $this->assertCount(1, $batch->documents()->get());
        }

        $invalid = Register::factory()->create(['insurance' => null]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([$register, $invalid]));
            $this->fail('Invalid register data should roll back the whole addition.');
        } catch (ValidationException) {
            $this->assertCount(1, $batch->documents()->get());
            $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        }

        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([$register]));
            $this->fail('A register with an uncertain CT-e result cannot be included.');
        } catch (ValidationException) {
            $this->assertCount(1, $batch->documents()->get());
        }
    }

    public function test_it_blocks_active_or_legacy_batch_documents_and_failed_transmission_evidence(): void
    {
        foreach ([CteDocumentStatusEnum::QUEUED, CteDocumentStatusEnum::AUTHORIZING, CteDocumentStatusEnum::RECONCILIATION_REQUIRED] as $status) {
            $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
            CteDocument::factory()->create([
                'cte_emission_batch_id' => $batch->id,
                'status' => $status,
                'emitter' => 'direct',
                'workflow_origin' => 'controlled_v1',
            ]);

            try {
                app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
                $this->fail("A batch containing {$status->value} must block inclusion.");
            } catch (ValidationException) {
                $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
            }
        }

        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'lab',
            'workflow_origin' => null,
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('Legacy batch documents must remain read-only.');
        } catch (ValidationException) {
            $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        }

        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'access_key' => str_repeat('2', 44),
            'payload_xml' => '<CTe/>',
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('Failed transmission evidence must block inclusion.');
        } catch (ValidationException) {
            $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        }
    }

    public function test_it_blocks_mdfes_with_uncertain_events_and_failed_transmission_evidence(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $mdfe->events()->create([
            'event_type' => '110112',
            'sequence' => 1,
            'attempt' => 1,
            'status' => 'reconciliation_required',
            'event_id' => 'ID110112'.str_repeat('2', 44).'01',
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('An unresolved MDF-e event must block inclusion.');
        } catch (ValidationException) {
            $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        }

        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'access_key' => str_repeat('3', 44),
            'payload_xml' => '<MDFe/>',
        ]);
        try {
            app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, collect([Register::factory()->create()]));
            $this->fail('Failed MDF-e transmission evidence must block inclusion.');
        } catch (ValidationException) {
            $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        }
    }
}
