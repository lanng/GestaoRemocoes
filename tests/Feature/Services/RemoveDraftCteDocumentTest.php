<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Models\CteEmissionBatch;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Cte\RemoveDraftCteDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemoveDraftCteDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_a_draft_document_and_keeps_the_register(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $nfe = CteDocumentNfe::query()->create([
            'cte_document_id' => $document->id,
            'access_key' => str_repeat('1', 44),
            'xml_path' => 'nfe/'.str_repeat('1', 44).'/original.xml',
            'sha256' => str_repeat('a', 64),
            'extracted_data' => [],
        ]);

        app(RemoveDraftCteDocument::class)->handle($document);

        $this->assertModelMissing($document);
        $this->assertModelMissing($nfe);
        $this->assertModelExists($document->register);
        $this->assertModelMissing($batch);
    }

    public function test_it_removes_an_unsubmitted_standalone_draft_and_keeps_the_archived_nfe_xml(): void
    {
        Storage::fake('dfe');
        $xmlPath = 'nfe/'.str_repeat('2', 44).'/'.str_repeat('b', 64).'.xml';
        Storage::disk('dfe')->put($xmlPath, '<NFe>original</NFe>');

        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $nfe = CteDocumentNfe::query()->create([
            'cte_document_id' => $document->id,
            'access_key' => str_repeat('2', 44),
            'xml_path' => $xmlPath,
            'sha256' => str_repeat('b', 64),
            'extracted_data' => [],
        ]);

        app(RemoveDraftCteDocument::class)->handle($document);

        $this->assertModelMissing($document);
        $this->assertModelMissing($nfe);
        Storage::disk('dfe')->assertExists($xmlPath);
    }

    public function test_it_refuses_to_remove_a_draft_with_fiscal_or_transmission_evidence(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
            'access_key' => str_repeat('3', 44),
            'cte_number' => '900123',
            'payload_xml' => '<CTe/>',
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }

    public function test_it_refuses_to_remove_a_cte_linked_to_a_manifest_or_active_reservation(): void
    {
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $manifest->cteDocuments()->attach($document->id, ['position' => 1]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => $document->id,
            'mdfe_document_id' => $manifest->id,
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }

    public function test_it_keeps_a_draft_batch_when_other_documents_remain(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $remaining = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        app(RemoveDraftCteDocument::class)->handle($document);

        $this->assertModelExists($batch);
        $this->assertModelExists($remaining);
    }

    public function test_removing_all_new_drafts_recompletes_a_mixed_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);
        $draft = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        app(RemoveDraftCteDocument::class)->handle($draft);

        $this->assertModelExists($batch);
        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $batch->documents()->firstOrFail()->status);
    }

    public function test_it_rejects_non_draft_documents_or_batches(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }

    public function test_it_rejects_a_non_draft_document_from_a_draft_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->expectException(ValidationException::class);

        app(RemoveDraftCteDocument::class)->handle($document);
    }
}
