<?php

namespace Tests\Feature\Filament;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Resources\MdfeDocumentResource\Pages\CreateMdfeDocument;
use App\Filament\Resources\MdfeDocumentResource\Pages\ManageMdfeDocuments;
use App\Filament\Resources\MdfeDocumentResource\Pages\ViewMdfeDocument;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Models\MdfeEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MdfeDocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Storage::fake('dfe');
    }

    public function test_lists_manifest_history_and_can_filter_open_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $open = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('1', 44),
            'protocol' => '935260000000001',
            'fiscal_status_code' => '100',
        ]);
        $closed = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('2', 44),
            'protocol' => '935260000000002',
            'fiscal_status_code' => '132',
        ]);
        $cancelled = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::CANCELLED,
            'emitter' => 'direct',
            'access_key' => str_repeat('3', 44),
            'fiscal_status_code' => '101',
        ]);
        $rejected = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::REJECTED,
            'emitter' => 'direct',
            'access_key' => null,
        ]);
        $draft = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
            'emitter' => 'direct',
            'access_key' => null,
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertSuccessful()
            ->assertActionVisible('create')
            ->assertCanSeeTableRecords([$open, $closed, $cancelled, $rejected, $draft])
            ->filterTable('fiscal_status_code', '100')
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed, $cancelled, $rejected, $draft]);

        $this->get(MdfeDocumentResource::getUrl('index'))->assertSuccessful();
    }

    public function test_standalone_manifest_hides_the_batch_link(): void
    {
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('4', 44),
            'fiscal_status_code' => '100',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionHidden('viewBatch', $document);
    }

    public function test_manifest_list_downloads_archived_event_xml(): void
    {
        $accessKey = str_repeat('8', 44);
        $eventPath = "mdfe/{$accessKey}/eventos/ID110112{$accessKey}01-proc.xml";
        Storage::disk('dfe')->put($eventPath, '<procEventoMDFe>listed</procEventoMDFe>');
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => $accessKey,
        ]);
        $event = MdfeEvent::factory()->create([
            'mdfe_document_id' => $document->id,
            'event_type' => '110112',
            'attempt' => 1,
            'status' => 'registered',
            'xml_path' => $eventPath,
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionVisible('downloadEventXml', $document->getKey())
            ->callTableAction('downloadEventXml', $document->getKey(), data: ['event_id' => $event->id])
            ->assertFileDownloaded(basename($eventPath), '<procEventoMDFe>listed</procEventoMDFe>');
    }

    public function test_resource_exposes_the_new_preparation_page(): void
    {
        $this->get(MdfeDocumentResource::getUrl('create'))->assertSuccessful();
    }

    public function test_create_page_keeps_its_batch_context_from_the_query_string(): void
    {
        $batch = CteEmissionBatch::factory()->create();

        $this->get(MdfeDocumentResource::getUrl('create', ['batch' => $batch->id]))
            ->assertSuccessful()
            ->assertSee('Lote de origem #'.$batch->id);
    }

    public function test_create_page_prefills_only_authorized_batch_ctes_with_its_issued_ciot_and_unique_tank_driver(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => \App\Enums\CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $ciot = Ciot::factory()->issued()->tankAlcohol()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);
        $driver = MdfeDriver::factory()->create(['line' => 'tank_alcohol']);
        $authorized = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'tank_alcohol'],
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'tank_alcohol'],
        ]);
        $otherBatchCte = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'tank_alcohol'],
        ]);

        Livewire::withQueryParams(['batch' => $batch->id])
            ->test(CreateMdfeDocument::class)
            ->assertSet('batchId', $batch->id)
            ->assertSet('data.line', 'tank_alcohol')
            ->assertSet('data.cte_document_ids', [$authorized->id])
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => array_keys($field->getOptions()) === [$authorized->id]
                && ! array_key_exists($otherBatchCte->id, $field->getOptions()))
            ->assertSet('data.ciot_mode', 'existing')
            ->assertSet('data.ciot_id', $ciot->id)
            ->assertSet('data.driver_id', $driver->id);
    }

    public function test_removal_driver_selector_suggests_the_fixed_driver_and_allows_an_override(): void
    {
        $fixed = MdfeDriver::factory()->create(['is_default' => true]);
        $alternative = MdfeDriver::factory()->create();
        MdfeDriver::factory()->create(['is_active' => false]);
        $tankDriver = MdfeDriver::factory()->create(['line' => 'tank_alcohol']);

        Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->assertFormFieldIsVisible('driver_id')
            ->assertFormFieldExists('driver_id', fn ($field): bool => $field->isRequired()
                && count($field->getOptions()) === 2
                && isset($field->getOptions()[$fixed->id], $field->getOptions()[$alternative->id]))
            ->assertSet('data.driver_id', $fixed->id)
            ->set('data.driver_id', $alternative->id)
            ->set('data.cte_document_ids', [])
            ->assertSet('data.driver_id', $alternative->id)
            ->set('data.line', 'tank_alcohol')
            ->assertSet('data.driver_id', $tankDriver->id)
            ->assertFormFieldExists('driver_id', fn ($field): bool => array_keys($field->getOptions()) === [$tankDriver->id]);

        $this->assertTrue($fixed->fresh()->is_default);
        $this->assertFalse($alternative->fresh()->is_default);
    }

    public function test_removal_without_a_fixed_driver_requires_an_explicit_selection(): void
    {
        $driver = MdfeDriver::factory()->create();

        Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->assertSet('data.driver_id', null)
            ->assertFormFieldIsVisible('driver_id')
            ->assertFormFieldExists('driver_id', fn ($field): bool => isset($field->getOptions()[$driver->id]))
            ->call('prepare')
            ->assertHasFormErrors(['driver_id' => 'required']);
    }

    public function test_removal_batch_preparation_suggests_its_fixed_driver(): void
    {
        $fixed = MdfeDriver::factory()->create(['is_default' => true]);
        $batch = CteEmissionBatch::factory()->create(['status' => 'completed']);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'vehicle_removal'],
        ]);

        Livewire::withQueryParams(['batch' => $batch->id])
            ->test(CreateMdfeDocument::class)
            ->assertSet('data.line', 'vehicle_removal')
            ->assertSet('data.driver_id', $fixed->id);
    }

    public function test_create_page_prefills_the_configured_endorsement_and_keeps_an_operator_override(): void
    {
        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO']);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->assertSee('A averbação padrão está preenchida.');
        $endorsementRows = collect($page->get('data.endorsements'));
        $endorsementRowId = $endorsementRows->keys()->first();

        $this->assertSame('AVERBACAO-PADRAO', $endorsementRows->first()['number']);

        $page->set("data.endorsements.{$endorsementRowId}.number", 'AVERBACAO-OVERRIDE');

        $this->assertSame(
            'AVERBACAO-OVERRIDE',
            $page->get("data.endorsements.{$endorsementRowId}.number"),
        );
    }

    public function test_create_page_requires_an_endorsement_when_no_default_is_configured(): void
    {
        config(['dfe.mdfe.default_endorsement' => null]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->assertSee('Não há valor padrão; informe ao menos uma averbação para continuar.');
        $endorsementRows = collect($page->get('data.endorsements'));

        $this->assertSame('', $endorsementRows->first()['number']);
    }

    public function test_create_page_reuses_ciot_fields_for_new_preparations(): void
    {
        Livewire::test(CreateMdfeDocument::class)
            ->assertSuccessful()
            ->assertSee('Preparar e enfileirar MDF-e')
            ->assertSet('data.ciot_mode', null)
            ->assertFormFieldExists('line')
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => $field->isPreloaded())
            ->assertFormFieldExists('ciot_mode')
            ->assertFormFieldExists('ciot_id')
            ->assertFormFieldExists('new_ciot.payer_id')
            ->assertFormFieldExists('new_ciot.distance_km')
            ->assertFormFieldExists('new_ciot.vehicle_ids')
            ->assertFormFieldDoesNotExist('vehicle_plate')
            ->assertSee('Criar e emitir um novo CIOT');
    }

    public function test_cte_selector_preloads_the_five_most_recent_eligible_documents_for_the_selected_line(): void
    {
        $documents = collect(range(1, 6))->map(fn (int $number): CteDocument => CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'cte_number' => (string) $number,
            'authorized_at' => now()->subMinutes(6 - $number),
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'vehicle_removal'],
        ]));
        $otherLineDocument = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'tank_alcohol'],
        ]);

        Livewire::test(CreateMdfeDocument::class)
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => $field->getOptions() === [])
            ->set('data.line', 'vehicle_removal')
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => array_keys($field->getOptions()) === $documents->reverse()->take(5)->pluck('id')->all())
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => ! array_key_exists($otherLineDocument->id, $field->getOptions()))
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => array_key_exists($documents->first()->id, $field->getSearchResults('1')))
            ->set('data.cte_document_ids', [$documents->first()->id])
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => str_starts_with($field->getOptionLabels()[$documents->first()->id] ?? '', 'CT-e 1/'))
            ->set('data.line', 'tank_alcohol')
            ->assertSet('data.cte_document_ids', [])
            ->assertFormFieldExists('cte_document_ids', fn ($field): bool => array_keys($field->getOptions()) === [$otherLineDocument->id]);
    }

    public function test_new_ciot_vehicle_selection_starts_empty_and_keeps_each_vehicle_independent(): void
    {
        MdfeDriver::factory()->create(['is_default' => true]);
        $driver = MdfeDriver::factory()->create();
        $firstVehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'BWK4B95']);
        $secondVehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'KWP5A14']);
        $cte = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'line' => 'vehicle_removal',
                'value' => '500.00',
                'fipe_value' => '10000.00',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);
        $otherCte = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'line' => 'vehicle_removal',
                'value' => '250.00',
                'fipe_value' => '5000.00',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->set('data.driver_id', $driver->id)
            ->set('data.cte_document_ids', [$cte->id])
            ->set('data.ciot_mode', 'new');

        $this->assertSame([], $page->get('data.new_ciot.vehicle_ids'));

        $page->set('data.new_ciot.vehicle_ids', [$firstVehicle->id]);

        $this->assertSame([$firstVehicle->id], $page->get('data.new_ciot.vehicle_ids'));

        $page->set('data.cte_document_ids', [$otherCte->id]);

        $this->assertSame([$firstVehicle->id], $page->get('data.new_ciot.vehicle_ids'));
        $this->assertSame($driver->id, $page->get('data.driver_id'));

        $page->set('data.new_ciot.vehicle_ids', [$secondVehicle->id]);

        $this->assertSame([$secondVehicle->id], $page->get('data.new_ciot.vehicle_ids'));
    }

    public function test_create_page_initializes_new_ciot_trip_dates_and_operational_indicator_defaults(): void
    {
        config([
            'ciot.defaults.ind_alto_desempenho' => true,
            'ciot.defaults.ind_retorno_vazio' => true,
            'ciot.defaults.composicao_veicular' => false,
        ]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->set('data.ciot_mode', 'new');

        $this->assertSame(
            today()->toDateString(),
            Carbon::parse($page->get('data.new_ciot.travel_start_at'))->toDateString(),
        );
        $this->assertSame(
            today()->addDay()->toDateString(),
            Carbon::parse($page->get('data.new_ciot.travel_end_at'))->toDateString(),
        );
        $this->assertTrue($page->get('data.new_ciot.indicators.ind_alto_desempenho'));
        $this->assertTrue($page->get('data.new_ciot.indicators.ind_retorno_vazio'));
        $this->assertFalse($page->get('data.new_ciot.indicators.composicao_veicular'));
    }

    public function test_create_page_prefills_the_new_ciot_from_selected_ctes_in_the_operator_order(): void
    {
        $first = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'line' => 'vehicle_removal',
                'value' => '500.00',
                'fipe_value' => '10000.00',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);
        $second = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'line' => 'vehicle_removal',
                'value' => '250.00',
                'fipe_value' => '5000.00',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->set('data.cte_document_ids', [$second->id, $first->id])
            ->set('data.ciot_mode', 'new');

        $this->assertSame([$second->id, $first->id], $page->get('data.cte_document_ids'));
        $this->assertSame('vehicle_removal', $page->get('data.new_ciot.line'));
        $this->assertSame('750.00', $page->get('data.new_ciot.freight_value'));
        $this->assertSame(
            today()->toDateString(),
            Carbon::parse($page->get('data.new_ciot.travel_start_at'))->toDateString(),
        );
        $this->assertSame(
            today()->addDay()->toDateString(),
            Carbon::parse($page->get('data.new_ciot.travel_end_at'))->toDateString(),
        );
        $this->assertTrue($page->get('data.new_ciot.indicators.ind_alto_desempenho'));
        $this->assertTrue($page->get('data.new_ciot.indicators.ind_retorno_vazio'));
        $this->assertFalse($page->get('data.new_ciot.indicators.composicao_veicular'));
    }

    public function test_create_page_preserves_the_travel_start_date_when_the_selected_ctes_change(): void
    {
        $snapshot = [
            'line' => 'vehicle_removal',
            'value' => '500.00',
            'fipe_value' => '10000.00',
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
        ];
        $first = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => $snapshot,
        ]);
        $second = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => $snapshot,
        ]);
        $travelStart = today()->addDays(5)->toDateString();
        $travelEnd = today()->addDays(8)->toDateString();

        $page = Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'vehicle_removal')
            ->set('data.cte_document_ids', [$first->id])
            ->set('data.ciot_mode', 'new')
            ->set('data.new_ciot.travel_start_at', $travelStart)
            ->set('data.new_ciot.travel_end_at', $travelEnd)
            ->set('data.cte_document_ids', [$second->id]);

        $this->assertSame($travelStart, $page->get('data.new_ciot.travel_start_at'));
        $this->assertSame($travelEnd, $page->get('data.new_ciot.travel_end_at'));
    }

    public function test_create_page_uses_the_nfe_issuer_cep_for_a_tank_alcohol_origin(): void
    {
        $document = CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'line' => 'tank_alcohol',
                'value' => '500.00',
                'cargo_value' => '10000.00',
                'cargo_weight_kg' => '2000.000',
                'dprev' => today()->addDay()->toDateString(),
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'rem' => [
                    'cnpj' => '12345678000195',
                    'xNome' => 'Usina de origem',
                    'cMun' => '3534609',
                    'xMun' => 'Osvaldo Cruz',
                    'uf' => 'SP',
                    'cep' => '17700000',
                ],
                'dest' => ['cnpj' => '98765432000110', 'xNome' => 'Destinatário fiscal'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140'],
                'delivery_party' => [
                    'cnpj' => '98765432000110',
                    'cMun' => '3508504',
                    'xMun' => 'Caçapava',
                    'uf' => 'SP',
                    'cep' => '12286140',
                ],
            ],
        ]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->set('data.line', 'tank_alcohol')
            ->set('data.cte_document_ids', [$document->id])
            ->set('data.ciot_mode', 'new');

        $this->assertSame('17700000', $page->get('data.new_ciot.origin.cep'));
    }

    public function test_create_page_rejects_a_legacy_cte_even_if_its_id_is_submitted_manually(): void
    {
        $driver = MdfeDriver::factory()->create();
        $legacy = CteDocument::factory()->authorized()->create([
            'workflow_origin' => null,
            'access_key' => str_repeat('7', 44),
        ]);
        $ciot = Ciot::factory()->issued()->create([
            'line' => 'vehicle_removal',
            'ciot_number' => '520032959999',
        ]);

        $page = Livewire::test(CreateMdfeDocument::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'cte_document_ids' => [$legacy->id],
                'ciot_mode' => 'existing',
                'ciot_id' => $ciot->id,
                'driver_id' => $driver->id,
            ])
            ->set('data.endorsements', ['test-row' => ['number' => 'AVERBACAO-001']])
            ->call('prepare');

        $page->assertHasFormErrors(['cte_document_ids']);

        $this->assertDatabaseCount('mdfe_documents', 0);
    }

    public function test_detail_page_shows_links_and_downloads_archived_fiscal_artifacts(): void
    {
        $accessKey = str_repeat('5', 44);
        $xmlPath = "mdfe/{$accessKey}/mdfe-proc.xml";
        $pdfPath = "mdfe/{$accessKey}/damdfe.pdf";
        $eventPath = "mdfe/{$accessKey}/eventos/ID110112{$accessKey}01-proc.xml";
        Storage::disk('dfe')->put($xmlPath, '<procMDFe>authorized</procMDFe>');
        Storage::disk('dfe')->put($pdfPath, '%PDF-damdfe');
        Storage::disk('dfe')->put($eventPath, '<procEventoMDFe>registered</procEventoMDFe>');

        $ciot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => null,
            'ciot_number' => '520032959999',
        ]);
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'xml_path' => $xmlPath,
            'pdf_path' => $pdfPath,
            'snapshot' => [
                'schema_version' => 2,
                'line' => 'vehicle_removal',
                'origin' => ['name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ]);
        $cte = CteDocument::factory()->authorized()->create([
            'cte_number' => '1234',
            'series' => '1',
            'access_key' => str_repeat('6', 44),
        ]);
        $document->cteDocuments()->attach($cte->id, ['position' => 1]);
        $event = MdfeEvent::factory()->create([
            'mdfe_document_id' => $document->id,
            'status' => 'registered',
            'event_type' => '110112',
            'protocol' => '135260000000001',
            'xml_path' => $eventPath,
        ]);

        $page = Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('CT-es vinculados')
            ->assertSee($cte->cte_number)
            ->assertSee($ciot->fullNumber())
            ->assertSee('Emitido')
            ->assertSee('Autorizado')
            ->assertSee('135260000000001')
            ->assertActionVisible('downloadXml')
            ->assertActionVisible('downloadPdf')
            ->assertActionVisible('downloadEventXml');

        $page->callAction('downloadXml')
            ->assertFileDownloaded(strtolower($accessKey).'-mdfe.xml', '<procMDFe>authorized</procMDFe>');
        $page->callAction('downloadPdf')
            ->assertFileDownloaded(strtolower($accessKey).'-damdfe.pdf', '%PDF-damdfe');
        $page->callAction('downloadEventXml', data: ['event_id' => $event->id])
            ->assertFileDownloaded(basename($eventPath), '<procEventoMDFe>registered</procEventoMDFe>');
    }

    public function test_detail_page_refreshes_sefaz_and_antt_results(): void
    {
        $ciot = Ciot::factory()->issued()->create([
            'response' => ['Codigo' => '110', 'Descricao' => 'CIOT autorizado pela ANTT.'],
        ]);
        $document = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'fiscal_status_message' => null,
        ]);
        $page = Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertSee('wire:poll.5s="refreshDocument"', false)
            ->assertSee('Resposta da ANTT')
            ->assertSee('CIOT autorizado pela ANTT.');

        $document->forceFill([
            'status' => CteDocumentStatusEnum::REJECTED,
            'fiscal_status_code' => '539',
            'fiscal_status_message' => 'MDF-e rejeitado pela SEFAZ no teste.',
            'error_code' => '539',
            'error_message' => 'Detalhe técnico da rejeição.',
        ])->save();
        $ciot->forceFill([
            'response' => ['Codigo' => '110', 'Descricao' => 'Resposta ANTT atualizada.'],
        ])->save();

        $page->call('refreshDocument')
            ->assertSee('MDF-e rejeitado pela SEFAZ no teste.')
            ->assertSee('Detalhe técnico da rejeição.')
            ->assertSee('Resposta ANTT atualizada.');
    }

    public function test_detail_page_deletes_an_unsubmitted_standalone_manifest_and_preserves_cte_and_ciot(): void
    {
        $ciot = Ciot::factory()->issued()->create(['cte_emission_batch_id' => null]);
        $cte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
        ]);
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $manifest->cteDocuments()->attach($cte->id, ['position' => 1]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $manifest->id,
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $manifest->id,
        ]);

        Livewire::test(ViewMdfeDocument::class, ['record' => $manifest->getRouteKey()])
            ->assertActionVisible('deleteDraft')
            ->callAction('deleteDraft')
            ->assertNotified()
            ->assertRedirect(MdfeDocumentResource::getUrl('index'));

        $this->assertModelMissing($manifest);
        $this->assertModelExists($ciot);
        $this->assertModelExists($cte);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'mdfe_document_id' => $manifest->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'mdfe_document_id' => $manifest->id,
        ]);
    }

    public function test_detail_page_hides_manifest_deletion_when_transmission_evidence_exists(): void
    {
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
            'response_xml' => '<retMDFe/>',
        ]);

        Livewire::test(ViewMdfeDocument::class, ['record' => $manifest->getRouteKey()])
            ->assertActionHidden('deleteDraft');
    }

    public function test_open_manifest_page_offers_close_retry_and_damdfe_actions_without_mutating_fiscal_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        Storage::disk('dfe')->put('mdfe/'.str_repeat('3', 44).'/mdfe-proc.xml', '<procMDFe/>');
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('3', 44),
            'protocol' => '935260000000003',
            'fiscal_status_code' => '100',
            'xml_path' => 'mdfe/'.str_repeat('3', 44).'/mdfe-proc.xml',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionVisible('closeMdfe', $document)
            ->assertTableActionVisible('downloadDamdfe', $document)
            ->assertTableActionVisible('viewBatch', $document)
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');

        MdfeEvent::factory()->create([
            'mdfe_document_id' => $document->id,
            'status' => 'registered',
            'event_id' => 'ID110112'.str_repeat('3', 44).'01',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionVisible('closeMdfe', $document);
    }

    public function test_sefaz_closed_manifest_offers_a_ciot_only_resume_action(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $document = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('5', 44),
            'protocol' => '935260000000005',
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ]);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionVisible('resumeCiotClosure', $document)
            ->assertTableActionHidden('closeMdfe', $document);

        Http::fake(['*' => Http::response([
            'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
            'Codigo' => '110',
            'DataEncerramento' => '2026-10-07T10:00:00-03:00',
        ], 200)]);

        Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('resumeCiotClosure')
            ->assertActionHidden('closeTransportOperation')
            ->callAction('resumeCiotClosure')
            ->assertNotified();

        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->refresh()->status);

        Livewire::test(ManageMdfeDocuments::class)
            ->assertTableActionHidden('resumeCiotClosure', $document->refresh());

        Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertActionHidden('resumeCiotClosure');
    }

    public function test_detail_page_exposes_cancel_only_for_open_manifest_and_requires_justification(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('7', 44),
            'protocol' => '935260000000007',
            'fiscal_status_code' => '100',
        ]);

        Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertActionVisible('cancelMdfe')
            ->callAction('cancelMdfe')
            ->assertHasActionErrors(['justification']);

        $document->forceFill([
            'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
            'fiscal_status_code' => '132',
        ])->save();

        Livewire::test(ViewMdfeDocument::class, ['record' => $document->getRouteKey()])
            ->assertActionHidden('cancelMdfe');
    }
}
