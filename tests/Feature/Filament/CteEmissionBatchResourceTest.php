<?php

namespace Tests\Feature\Filament;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ListCteEmissionBatches;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Resources\RegisterResource;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CityDistance;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Models\MdfeEvent;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CteEmissionBatchResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.env' => 'homologacao',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.gerar_base_url' => null,
            'ciot.api_key' => 'test-api-key',
            'ciot.natureza_fallback' => false,
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_the_batch_view_renders_the_mdfe_section_with_a_document(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'mdfe_number' => null,
            'protocol' => null,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertSee('MDF-e da viagem')
            ->assertSee('sem número');
    }

    public function test_the_batch_view_links_to_mdf_e_preparation_with_its_context(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'vehicle_removal'],
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('prepareMdfe')
            ->assertActionHasUrl('prepareMdfe', MdfeDocumentResource::getUrl('create', ['batch' => $batch->id]));
    }

    public function test_a_completed_batch_hides_ciot_and_mdfe_actions_when_its_ctes_are_covered_by_an_authorized_mdfe(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $secondCte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => $batch->id]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);
        $mdfe->cteDocuments()->attach($secondCte->id, ['position' => 2]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('generateCiot')
            ->assertActionHidden('prepareMdfe')
            ->assertActionHidden('emitMdfeDirect');
    }

    public function test_mdfe_preparation_is_hidden_when_every_eligible_cte_is_already_covered(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => $batch->id]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('prepareMdfe');
    }

    public function test_a_completed_batch_hides_mdfe_actions_when_its_ctes_are_covered_only_through_the_pivot(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => null]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('generateCiot')
            ->assertActionHidden('prepareMdfe')
            ->assertActionHidden('emitMdfeDirect');
    }

    public function test_direct_mdfe_emission_is_hidden_when_an_authorized_mdfe_is_linked_only_through_the_cte_pivot(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => null]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('emitMdfeDirect');
    }

    public function test_mdfe_preparation_remains_available_for_an_uncovered_cte_in_a_partially_covered_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $coveredCte = $this->createAuthorizedBatchCte($batch);
        $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => null]);
        $mdfe->cteDocuments()->attach($coveredCte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('prepareMdfe')
            ->assertActionHidden('emitMdfeDirect');
    }

    public function test_mdfe_from_another_batch_does_not_hide_direct_emission(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $this->createAuthorizedBatchCte($batch);

        $otherBatch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        $otherCte = $this->createAuthorizedBatchCte($otherBatch);
        $otherMdfe = $this->createAuthorizedMdfe(['cte_emission_batch_id' => $otherBatch->id]);
        $otherMdfe->cteDocuments()->attach($otherCte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('emitMdfeDirect');
    }

    #[DataProvider('recoverableMdfeStatusCases')]
    public function test_direct_mdfe_emission_stays_visible_for_recoverable_mdfe_statuses(CteDocumentStatusEnum $mdfeStatus): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe([
            'cte_emission_batch_id' => $batch->id,
            'status' => $mdfeStatus,
        ]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('emitMdfeDirect');
    }

    /** @return array<string, array{CteDocumentStatusEnum}> */
    public static function recoverableMdfeStatusCases(): array
    {
        return [
            'rejected' => [CteDocumentStatusEnum::REJECTED],
            'failed before authorization' => [CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION],
            'cancelled' => [CteDocumentStatusEnum::CANCELLED],
        ];
    }

    #[DataProvider('cancelledMdfeVisibilityCases')]
    public function test_mdfe_preparation_and_emission_are_available_after_fiscal_cancellation(array $cancellation): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $cte = $this->createAuthorizedBatchCte($batch);
        $mdfe = $this->createAuthorizedMdfe([
            'cte_emission_batch_id' => $batch->id,
            ...$cancellation,
        ]);
        $mdfe->cteDocuments()->attach($cte->id, ['position' => 1]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('prepareMdfe')
            ->assertActionVisible('emitMdfeDirect');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function cancelledMdfeVisibilityCases(): array
    {
        return [
            'fiscal status confirms cancellation' => [['fiscal_status' => FiscalDocumentStatusEnum::CANCELLED]],
            'cancellation event code confirms cancellation' => [['fiscal_status_code' => '101']],
        ];
    }

    /** @return array<string, array{?CiotStatusEnum, bool}> */
    public static function ciotGenerationVisibilityCases(): array
    {
        return [
            'no previous CIOT' => [null, true],
            'only cancelled CIOT' => [CiotStatusEnum::CANCELED, true],
            'draft CIOT' => [CiotStatusEnum::DRAFT, false],
            'pending CIOT' => [CiotStatusEnum::PENDING, false],
            'issued CIOT' => [CiotStatusEnum::ISSUED, false],
            'closed CIOT' => [CiotStatusEnum::CLOSED, false],
            'failed CIOT' => [CiotStatusEnum::FAILED, false],
        ];
    }

    #[DataProvider('ciotGenerationVisibilityCases')]
    public function test_generate_ciot_visibility_matches_the_batch_creation_guard(?CiotStatusEnum $existingStatus, bool $visible): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        if ($existingStatus !== null) {
            Ciot::factory()->create([
                'cte_emission_batch_id' => $batch->id,
                'status' => $existingStatus,
            ]);
        }

        $component = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id]);

        if ($visible) {
            $component->assertActionVisible('generateCiot');

            return;
        }

        $component->assertActionHidden('generateCiot');
    }

    public function test_failed_batch_ciot_keeps_the_reemission_recovery_action_available(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::FAILED,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('generateCiot')
            ->assertActionVisible('reemitCiot');
    }

    public function test_the_damdfe_download_action_is_available_for_an_authorized_mdfe_with_stored_xml(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => str_repeat('1', 44),
            'xml_path' => 'mdfe/'.str_repeat('1', 44).'/mdfe-proc.xml',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('downloadDamdfe');
    }

    public function test_the_close_mdfe_action_is_available_for_a_new_attempt_or_a_confirmed_correctable_rejection(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('1', 44),
            'protocol' => '935260000000001',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('closeMdfe');

        MdfeEvent::factory()->create([
            'mdfe_document_id' => $mdfe->id,
            'event_type' => '110112',
            'sequence' => 1,
            'attempt' => 1,
            'event_id' => 'ID110112'.str_repeat('1', 44).'01',
            'status' => 'rejected_retryable',
            'error_code' => '614',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('closeMdfe');

        $mdfe->events()->firstOrFail()->forceFill(['status' => 'registered', 'error_code' => null])->save();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('closeMdfe');
    }

    public function test_batch_offers_a_separate_resume_action_for_a_ciot_after_sefaz_closed_the_mdfe(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        $ciot = Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'access_key' => str_repeat('2', 44),
            'protocol' => '935260000000002',
            'fiscal_status_code' => '132',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('resumeCiotClosure')
            ->assertActionHidden('closeMdfe');

        $ciot->forceFill(['status' => CiotStatusEnum::CLOSED])->save();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('resumeCiotClosure');
    }

    public function test_the_mdfe_action_is_shown_when_ctes_are_authorized(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('emitMdfeDirect')
            ->assertActionDoesNotExist('emitMdfe')
            ->mountAction('emitMdfeDirect')
            ->assertFormFieldExists('ciot_id', 'mountedActionForm')
            ->assertFormFieldDoesNotExist('route_ufs', 'mountedActionForm')
            ->assertFormFieldDoesNotExist('loading_cep', 'mountedActionForm')
            ->assertFormFieldDoesNotExist('unloading_cep', 'mountedActionForm')
            ->assertFormFieldDoesNotExist('payment_method', 'mountedActionForm');
    }

    public function test_the_direct_mdfe_action_prefills_the_configured_endorsement(): void
    {
        config([
            'dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO',
        ]);
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
        ]);

        $page = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->mountAction('emitMdfeDirect');
        $endorsementRows = $page->get('mountedActionsData.0.endorsements');

        $this->assertSame('AVERBACAO-PADRAO', collect($endorsementRows)->first()['number']);
    }

    public function test_the_direct_mdfe_action_explains_when_batch_ctes_are_not_eligible(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'direct',
            'workflow_origin' => null,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('emitMdfeDirect')
            ->assertActionDisabled('emitMdfeDirect');
    }

    public function test_the_direct_mdfe_action_stays_visible_but_disabled_when_a_batch_cte_failed(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'vehicle_removal'],
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'workflow_origin' => 'controlled_v1',
            'error_message' => 'A placa do veículo não foi aceita para emissão.',
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionVisible('prepareMdfe')
            ->assertActionVisible('emitMdfeDirect')
            ->assertActionDisabled('emitMdfeDirect');
    }

    public function test_the_direct_mdfe_action_prefills_a_unique_tank_driver_and_latest_batch_ciot(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        $ciot = Ciot::factory()->issued()->tankAlcohol()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);
        $driver = MdfeDriver::factory()->create(['line' => 'tank_alcohol']);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'tank_alcohol'],
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->mountAction('emitMdfeDirect')
            ->assertSet('mountedActionsData.0.ciot_id', $ciot->id)
            ->assertSet('mountedActionsData.0.driver_id', $driver->id);
    }

    public function test_the_documents_table_shows_the_latest_cte_emission_failure_reason(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $failed = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            'error_message' => 'Falha ao emitir o CT-e: placa ABC1234 não cadastrada.',
        ]);
        $rejected = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::REJECTED,
            'fiscal_status_code' => '539',
            'fiscal_status_message' => 'Rejeição: placa do veículo incompatível.',
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertSee('Falha ao emitir o CT-e: placa ABC1234 não cadastrada.')
            ->assertSee('539 · Rejeição: placa do veículo incompatível.');
    }

    public function test_the_reconcile_mdfe_action_closes_a_reconciliation_with_the_access_key(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('reconcileMdfe')
            ->callAction('reconcileMdfe', data: [
                'access_key' => '35260912563112000130580010000001231123456785',
                'protocol' => '135260000123456',
            ]);

        $mdfe = MdfeDocument::query()->where('cte_emission_batch_id', $batch->id)->firstOrFail();

        $this->assertSame('authorized', $mdfe->status->value);
        $this->assertSame('000000123', $mdfe->mdfe_number);
        $this->assertSame('001', $mdfe->series);
        $this->assertSame('135260000123456', $mdfe->protocol);
    }

    public function test_the_reconcile_mdfe_action_is_hidden_for_legacy_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'agent',
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionHidden('reconcileMdfe');
    }

    public function test_generate_ciot_action_creates_and_emits_a_linked_ciot(): void
    {
        config(['ciot.removal.weight_per_vehicle_kg' => 2000]);

        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = Company::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032951111'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329511110001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => '520032951111',
            ], 200),
        ]);

        Queue::fake();

        $page = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasNoActionErrors()
            ->assertNoRedirect();

        $ciot = Ciot::query()->where('cte_emission_batch_id', $batch->id)->firstOrFail();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032951111', $ciot->ciot_number);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);
        $page->assertSee('CIOT da viagem')
            ->assertSee('5200329511110001')
            ->assertSee('Emitido');

        Queue::assertNothingPushed();
    }

    public function test_generate_ciot_action_sends_additional_payers_cnpjs_to_the_antt(): void
    {
        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = Company::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        Company::factory()->create([
            'name' => 'Copart Pirapora do Bom Jesus', 'cnpj' => '14517191000330',
            'city' => 'Pirapora do Bom Jesus', 'state' => 'SP', 'ibge_code' => '3539506',
        ]);
        Company::factory()->create([
            'name' => 'Copart Osasco', 'cnpj' => '14517191000410',
            'city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3518800',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032952222'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329522220001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => '520032952222',
            ], 200),
        ]);

        Queue::fake();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'fractioned',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'additional_payers' => ['14517191000330', '14517191000410'],
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasNoActionErrors();

        $ciot = Ciot::query()->where('cte_emission_batch_id', $batch->id)->firstOrFail();

        $this->assertSame(['14517191000330', '14517191000410'], $ciot->additional_payers);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), 'DeclaracaoOperacaoTransporte')) {
                return false;
            }

            return ($request->data()['DadosCarga'] ?? [])['ContratantesCargFrac'] === [
                '14517191000330',
                '14517191000410',
            ];
        });
    }

    public function test_generate_ciot_action_rejects_the_payer_repeated_as_additional_b119(): void
    {
        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);
        $payer = Company::factory()->create([
            'name' => 'Copart Caçapava', 'cnpj' => '14517191000925',
            'city' => 'Caçapava', 'state' => 'SP', 'ibge_code' => '3508504',
        ]);
        Company::factory()->create([
            'name' => 'Copart Osasco', 'cnpj' => '14517191000410',
            'city' => 'Osasco', 'state' => 'SP', 'ibge_code' => '3518800',
        ]);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609', 'destination_ibge' => '3508504',
            'km' => 716, 'fetched_at' => now(),
        ]);

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);

        Queue::fake();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('generateCiot', data: [
                'operation_type' => 'fractioned',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'additional_payers' => ['14517191000925', '14517191000410'],
                'origin.ibge' => '3534609',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '500.00',
                'cargo_weight_kg' => '2000',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->assertHasActionErrors();

        $this->assertSame(0, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_reemit_ciot_action_reissues_a_failed_ciot(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => '35260912563112000130570010000028171839975374',
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);
        $ciot = Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::FAILED,
            'error_message' => 'Rejeição anterior',
            'id_operacao_transporte' => '520032959998',
        ]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true, 'Dados' => ['CIOT' => '520032952222'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329522220002',
                'CodigoVerificador' => '0002',
                'IdOperacaoTransporte' => '520032952222',
            ], 200),
        ]);

        $page = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->callAction('reemitCiot')
            ->assertNotified('CIOT reemitido: 5200329522220002')
            ->assertNoRedirect()
            ->assertSee('5200329522220002')
            ->assertSee('Emitido');

        $ciot->refresh();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032952222', $ciot->ciot_number);
        $this->assertSame('0002', $ciot->verifier_code);
        $this->assertNotSame('520032959998', $ciot->id_operacao_transporte);
    }

    public function test_reemit_ciot_warns_when_the_batch_has_no_failed_ciot(): void
    {
        Http::fake();

        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);

        // Sem CIOT com falha a ação fica oculta (e o Filament desabilita
        // ações ocultas), então o ramo null do guard só ocorre na corrida em
        // que o CIOT falho deixa de existir entre o render e o clique —
        // invocamos a closure real pela API pública da ação.
        $component = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id]);

        $component->instance()->getAction('reemitCiot')->call();

        $component->assertNotified('Nenhum CIOT com falha neste lote.');

        $this->assertSame(0, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_generate_ciot_action_blocks_duplicates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'company' => 'copart', 'vehicle_plate' => 'ABC1234',
                'origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Caçapava',
                'value' => '500.00', 'fipe_value' => '10000.00',
            ],
        ]);
        Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::ISSUED,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionHidden('generateCiot');
    }

    public function test_the_batch_list_uses_translated_labels_and_brasilia_dates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'execution_mode' => 'dry_run',
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'created_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
        ]);

        $component = Livewire::test(ListCteEmissionBatches::class)
            ->assertCanSeeTableRecords([$batch])
            ->assertTableColumnFormattedStateSet('status', 'Concluído com erros', $batch)
            ->assertTableColumnFormattedStateSet('execution_mode', 'Simulação', $batch)
            ->assertTableColumnFormattedStateSet('approved_at', '12/08/2026 12:30', $batch)
            ->assertTableColumnFormattedStateSet('created_at', '12/08/2026 13:30', $batch)
            ->assertTableColumnExists('status', fn ($column): bool => $column->getLabel() === 'Situação')
            ->assertTableColumnExists('execution_mode', fn ($column): bool => $column->getLabel() === 'Modo')
            ->assertTableColumnExists('approved_at', fn ($column): bool => $column->getLabel() === 'Aprovado em')
            ->assertTableColumnExists('created_at', fn ($column): bool => $column->getLabel() === 'Criado em')
            ->assertTableColumnExists('documents_count', fn ($column): bool => $column->getLabel() === 'Itens')
            ->assertTableActionHasLabel('view', 'Ver lote', $batch)
            ->assertTableHeaderActionsExistInOrder([]);

        $this->assertSame(
            CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->label(),
            $component->instance()->getTable()->getFilter('status')->getOptions()[CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS->value],
        );
        $this->assertSame('Situação', $component->instance()->getTable()->getFilter('status')->getLabel());
        $this->assertSame('Modo', $component->instance()->getTable()->getFilter('execution_mode')->getLabel());
        $this->assertSame([
            'dry_run' => 'Simulação',
            'live' => 'Emissão real',
        ], $component->instance()->getTable()->getFilter('execution_mode')->getOptions());
    }

    public function test_the_batch_detail_uses_translated_labels_and_actions(): void
    {
        $creator = User::factory()->create(['name' => 'Criador do lote']);
        $approver = User::factory()->create(['name' => 'Aprovador do lote']);
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
            'execution_mode' => 'live',
            'created_by' => $creator->id,
            'approved_by' => $approver->id,
            'approved_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
            'processing_started_at' => Carbon::create(2026, 8, 12, 16, 30, 'UTC'),
            'completed_at' => Carbon::create(2026, 8, 12, 17, 30, 'UTC'),
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'fipe_value' => '43897.00',
                'value' => '1250.00',
            ],
        ]);
        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Valor total da carga')
            ->assertSee('43.897,00')
            ->assertSee('Valor total do transporte')
            ->assertSee('1.250,00')
            ->assertSee('Situação')
            ->assertSee('Rascunho')
            ->assertSee('Modo')
            ->assertSee('Emissão real')
            ->assertSee('Criado por')
            ->assertSee('Criador do lote')
            ->assertSee('Aprovado por')
            ->assertSee('Aprovador do lote')
            ->assertSee('Aprovado em')
            ->assertSee('12/08/2026 12:30')
            ->assertSee('Processamento iniciado em')
            ->assertSee('12/08/2026 13:30')
            ->assertSee('Concluído em')
            ->assertSee('12/08/2026 14:30')
            ->assertActionHasLabel('delete', 'Excluir lote')
            ->assertActionHasLabel('approve', 'Aprovar lote');
    }

    public function test_the_batch_detail_polls_for_batch_updates(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('wire:poll.5s="refreshBatch"', false);
    }

    public function test_refresh_batch_reloads_the_latest_batch_status(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
        ]);
        $component = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()]);

        $batch->update([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'completed_at' => now(),
        ]);

        $component
            ->call('refreshBatch')
            ->assertSee('Concluído com erros');
    }

    public function test_refresh_batch_updates_ciot_status_and_failed_ciot_has_a_detail_link(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $ciot = Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::PENDING,
            'ciot_number' => '520032959999',
        ]);
        $page = Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Emitindo');

        $ciot->forceFill([
            'status' => CiotStatusEnum::ISSUED,
            'ciot_number' => '520032951234',
            'verifier_code' => '0001',
        ])->save();

        $page->call('refreshBatch')
            ->assertSee('Emitido')
            ->assertSee('5200329512340001');

        $ciot->forceFill([
            'status' => CiotStatusEnum::FAILED,
            'error_code' => '217',
            'error_message' => 'Rejeição atual da ANTT.',
        ])->save();

        $page->call('refreshBatch')
            ->assertSee('Falhou')
            ->assertSee('Rejeição atual da ANTT.')
            ->assertSee('Ver detalhes do CIOT')
            ->assertSee(CiotResource::getUrl('view', ['record' => $ciot]), false);
    }

    public function test_the_delete_action_is_only_available_for_draft_batches(): void
    {
        $draft = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $approved = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->assertTableActionHasLabel('delete', 'Excluir lote', $draft)
            ->assertTableActionHidden('delete', $approved);
    }

    public function test_batch_deletion_preserves_a_draft_cte_with_transmission_evidence_as_a_standalone_document(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
            'payload_xml' => '<CTe/>',
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->assertTableActionVisible('delete', $batch)
            ->callTableAction('delete', $batch);

        $this->assertModelMissing($batch);
        $this->assertModelExists($document);
        $this->assertNull($document->fresh()->cte_emission_batch_id);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_list(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        Livewire::test(ListCteEmissionBatches::class)
            ->callTableAction('delete', $batch);

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_a_draft_batch_can_be_deleted_from_the_batch_detail(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->getRouteKey()])
            ->callAction('delete')
            ->assertRedirect(CteEmissionBatchResource::getUrl('index'));

        $this->assertModelMissing($batch);
        $this->assertModelMissing($document);
    }

    public function test_documents_show_translated_state_and_the_register_action(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $reconciliationDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertCanSeeTableRecords([$document])
            ->assertTableColumnFormattedStateSet('status', 'Falha antes da autorização', $document)
            ->assertTableColumnExists('cte_number', fn ($column): bool => $column->getLabel() === 'Número do CT-e')
            ->assertTableColumnExists('authorized_at', fn ($column): bool => $column->getLabel() === 'Autorizado em')
            ->assertTableActionHasLabel('retry', 'Tentar novamente', $document)
            ->assertTableActionHasLabel('reconcile', 'Conciliar', $reconciliationDocument)
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document);

        $this->assertArrayHasKey('register', $component->instance()->getTable()->getQuery()->getEagerLoads());
        $this->assertSame(25, $component->instance()->getTable()->getDefaultPaginationPageOption());
    }

    public function test_the_documents_table_polls_for_document_updates(): void
    {
        $batch = CteEmissionBatch::factory()->create();

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ]);

        $this->assertSame('5s', $component->instance()->getTable()->getPollingInterval());
    }

    public function test_a_draft_document_can_be_removed_from_a_draft_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('remove', 'Retirar do lote', $document)
            ->callTableAction('remove', $document);

        $this->assertModelMissing($document);
        $this->assertModelMissing($batch);
        $this->assertModelExists($document->register);
    }

    public function test_failed_documents_can_be_retried_in_bulk_and_reopen_the_batch(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
        ]);
        $failed = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
        ]);
        $authorized = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableBulkActionHasLabel('retrySelected', 'Tentar novamente selecionados')
            ->callTableBulkAction('retrySelected', [$failed, $authorized]);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $failed->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::PROCESSING, $batch->refresh()->status);
    }

    public function test_legacy_documents_cannot_be_retried_or_reconciled_from_the_documents_table(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $legacy = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'lab',
            'status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHidden('retry', $legacy)
            ->assertTableActionHidden('reconcile', $legacy);
    }

    public function test_the_register_action_links_to_the_complete_register(): void
    {
        $this->app->setLocale('pt_BR');

        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'vehicle_model' => 'Sprinter 416',
            'vehicle_plate' => 'ABC1D23',
            'vehicle_id' => 'VEH-987',
            'company' => 'millan',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Campinas',
            'deadline_withdraw' => '2026-08-10',
            'deadline_delivery' => '2026-08-12',
            'collected_date' => '2026-08-11',
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 18, 45, 'UTC'),
            'driver' => 'João da Silva',
            'driver_plate' => 'XYZ9K87',
            'tow_yard' => 'Pátio Central',
            'status' => RegisterStatusEnum::COLLECTED,
            'payment_code' => 'PAY-123',
            'insurance' => 'Allianz Seguros',
            'fipe_value' => '43897.00',
            'value' => '1250.00',
            'pdf_path' => 'registers/abc1d23.pdf',
            'notes' => 'Carga liberada para transporte.',
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        $component = Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertTableActionHasLabel('viewRegister', 'Ver registro', $document)
            ->mountTableAction('viewRegister', $document)
            ->assertTableActionMounted('viewRegister')
            ->assertSee('Identificação')
            ->assertSee('Rota e prazos')
            ->assertSee('Operação')
            ->assertSee('Financeiro')
            ->assertSee('Veículo')
            ->assertSee('Sprinter 416')
            ->assertSee('Placa')
            ->assertSee('ABC1D23')
            ->assertSee('Código do veículo')
            ->assertSee('VEH-987')
            ->assertSee('Empresa')
            ->assertSee('millan')
            ->assertSee('Origem')
            ->assertSee('São Paulo')
            ->assertSee('Destino')
            ->assertSee('Campinas')
            ->assertSee('Retirada até')
            ->assertSee('10/08/2026')
            ->assertSee('Entrega até')
            ->assertSee('12/08/2026')
            ->assertSee('Data da recolha')
            ->assertSee('11/08/2026')
            ->assertSee('Entrega confirmada')
            ->assertSee('12/08/2026 15:45')
            ->assertSee('Motorista')
            ->assertSee('João da Silva')
            ->assertSee('Placa guincho')
            ->assertSee('XYZ9K87')
            ->assertSee('Pátio')
            ->assertSee('Pátio Central')
            ->assertSee('Situação')
            ->assertSee('Coletado')
            ->assertSee('Código pagamento')
            ->assertSee('PAY-123')
            ->assertSee('Seguradora')
            ->assertSee('Allianz Seguros')
            ->assertSee('Valor FIPE')
            ->assertSee('R$')
            ->assertSee('43.897,00')
            ->assertSee('Valor do serviço')
            ->assertSee('1.250,00')
            ->assertDontSee('PDF')
            ->assertDontSee('registers/abc1d23.pdf')
            ->assertSee('Observações')
            ->assertSee('Carga liberada para transporte.')
            ->assertSee('Abrir registro completo');

        $action = $component->instance()->getMountedTableAction();

        $this->assertNull($action->getModalSubmitAction());
        $this->assertSame(MaxWidth::FiveExtraLarge, $action->getModalWidth());
        $this->assertTrue($action->isModalHeaderSticky());
        $this->assertTrue($action->isModalFooterSticky());
        $this->assertSame(
            RegisterResource::getUrl('view', ['record' => $register]),
            $action->getModalAction('openRegister')->getUrl(),
        );
    }

    public function test_the_register_action_shows_placeholders_for_missing_optional_data(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $register = Register::factory()->create([
            'collected_date' => null,
            'delivery_confirmed_at' => null,
            'driver' => null,
            'driver_plate' => null,
            'tow_yard' => null,
            'payment_code' => null,
            'insurance' => null,
            'fipe_value' => null,
            'pdf_path' => null,
            'notes' => null,
        ]);
        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->mountTableAction('viewRegister', $document)
            ->assertSee('Não informado');
    }

    public function test_completed_batch_can_include_registers_for_draft_review(): void
    {
        Queue::fake();
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        $register = Register::factory()->create();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertActionVisible('addRegisters')
            ->mountAction('addRegisters')
            ->assertFormFieldExists('register_ids', 'mountedActionForm')
            ->setActionData(['register_ids' => [(string) $register->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Registros incluídos para revisão');

        $batch = $batch->refresh();
        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $batch->status);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $batch->documents->first()->status);
        Queue::assertNotPushed(\App\Jobs\EmitCteDirectJob::class);
    }

    public function test_add_registers_action_reports_fiscal_guards_and_missing_ids_without_partial_changes(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch->id]);
        $register = Register::factory()->create();

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->mountAction('addRegisters')
            ->setActionData(['register_ids' => [(string) $register->id]])
            ->callMountedAction()
            ->assertNotified('Não foi possível incluir os registros');

        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        $this->assertCount(0, $batch->documents()->get());

        $batch->ciots()->delete();
        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->mountAction('addRegisters')
            ->setActionData(['register_ids' => [(string) $register->id, '999999999']])
            ->callMountedAction()
            ->assertNotified('Não foi possível incluir os registros');

        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        $this->assertCount(0, $batch->documents()->get());

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->mountAction('addRegisters')
            ->setActionData(['register_ids' => ['999999999999999999999999999999999999']])
            ->callMountedAction()
            ->assertNotified('Não foi possível incluir os registros');

        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $batch->refresh()->status);
        $this->assertCount(0, $batch->documents()->get());
    }

    public function test_add_registers_requires_update_permission_when_view_is_allowed(): void
    {
        Gate::before(fn (User $user, string $ability): ?bool => match ($ability) {
            'view' => true,
            'update' => false,
            default => null,
        });
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);

        $this->assertTrue(CteEmissionBatchResource::canView($batch));
        $this->assertFalse(CteEmissionBatchResource::canEdit($batch));

        Livewire::test(ViewCteEmissionBatch::class, ['record' => $batch->id])
            ->assertSuccessful()
            ->assertActionHidden('addRegisters');
    }

    public function test_reopened_mixed_batch_keeps_fiscal_details_for_authorized_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);
        $authorized = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
            'cte_number' => '900100',
            'snapshot' => [
                'rem' => ['xNome' => 'Remetente congelado'],
                'dest' => ['xNome' => 'Destinatário congelado'],
            ],
        ]);
        $draft = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::DRAFT,
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);
        $rejected = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::REJECTED,
            'emitter' => 'direct',
            'workflow_origin' => 'controlled_v1',
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewCteEmissionBatch::class,
        ])
            ->assertSee('900100')
            ->assertSee('Autorizado')
            ->assertTableActionHidden('resolveSender', $authorized)
            ->assertTableActionVisible('resolveSender', $draft)
            ->assertTableActionHidden('retry', $rejected)
            ->assertTableBulkActionHidden('retrySelected')
            ->assertTableColumnStateSet('party_review_status', 'Rejeitado', record: $rejected)
            ->assertTableColumnStateSet('confirmed_sender', 'Remetente congelado', record: $authorized)
            ->assertTableColumnStateSet('confirmed_destination', 'Destinatário congelado', record: $authorized);
    }

    private function createAuthorizedBatchCte(CteEmissionBatch $batch): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => ['line' => 'vehicle_removal'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createAuthorizedMdfe(array $attributes = []): MdfeDocument
    {
        return MdfeDocument::factory()->create(array_merge([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => str_repeat('4', 44),
            'protocol' => '935260000000004',
            'fiscal_status_code' => '100',
        ], $attributes));
    }
}
