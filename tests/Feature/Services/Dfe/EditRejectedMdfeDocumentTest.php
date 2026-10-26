<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Resources\MdfeDocumentResource\Pages\CreateMdfeDocument;
use App\Filament\Resources\MdfeDocumentResource\Pages\EditMdfeDocument;
use App\Filament\Resources\MdfeDocumentResource\Pages\ViewMdfeDocument;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Models\MdfeNumberSequence;
use App\Models\User;
use App\Services\Ciot\BuildMdfeEmissionSnapshot;
use App\Services\Dfe\AccessKey;
use App\Services\Dfe\EditRejectedMdfeDocument;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\TestCase;

class EditRejectedMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['dfe.tp_amb' => '2', 'dfe.emit.cnpj' => '12563112000130', 'dfe.mdfe.series' => '1', 'dfe.mdfe.tp_emis' => '1', 'app.env' => 'local']);
    }

    public function test_edits_creation_fields_preserving_saved_automatic_data_without_queueing_or_allocating(): void
    {
        $source = $this->rejected();
        $service = app(EditRejectedMdfeDocument::class);
        $data = $service->formData($source);
        $data['vehicle_plate'] = 'abc-1d23';
        $data['endorsements'] = [['number' => 'AVER-CORRIGIDA']];
        $data['route_ufs'] = ['MG'];
        $original = $source->snapshot;
        $ciotOriginal = $source->ciot->getAttributes();
        $result = $service->save($source, $data);

        $this->assertSame($source->id, $result->id);
        $this->assertSame('280', $result->mdfe_number);
        $this->assertSame('1', $result->series);
        $this->assertSame('BWK4B95', $result->snapshot['vehicle']['plate']);
        $this->assertSame(['AVER-CORRIGIDA'], $result->snapshot['insurance']['endorsements']);
        $this->assertSame(['MG'], $result->snapshot['route_ufs']);
        foreach (['origin', 'destination', 'loading_cities', 'destinations', 'cargo', 'payment', 'inf_lotacao'] as $section) {
            $this->assertSame($original[$section], $result->snapshot[$section]);
        }
        $this->assertSame($original['insurance']['policy_number'], $result->snapshot['insurance']['policy_number']);
        $this->assertSame('<MDFe>original</MDFe>', $result->payload_xml);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $result->status);
        $this->assertSame($ciotOriginal, $source->ciot->fresh()->getAttributes());
        $this->assertDatabaseCount('mdfe_documents', 1);
        $this->assertDatabaseCount('mdfe_number_sequences', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_saving_rejected_mdfe_uses_ciot_origin_for_loading_and_archives_original_attempt(): void
    {
        $source = $this->rejected();
        $ciot = $source->ciot;
        $origin = ['cidade' => 'São José do Rio Preto', 'uf' => 'SP', 'cep' => '15000000', 'ibge' => '3549805'];
        $ciot->forceFill(['origin' => $origin])->save();
        $snapshot = $source->snapshot;
        $snapshot['origin'] = ['ibge' => '3549805', 'name' => 'São José do Rio Preto', 'uf' => 'SP', 'cep' => '15000000'];
        $snapshot['loading_cities'] = [
            ['ibge' => '3548906', 'name' => 'São Carlos'],
            ['ibge' => '4124103', 'name' => 'Santo Antônio da Platina'],
            ['ibge' => '3549805', 'name' => 'São José do Rio Preto'],
        ];
        $snapshot['inf_lotacao']['loading_cep'] = '15000000';
        $source->forceFill(['snapshot' => $snapshot])->save();
        $original = $source->fresh();
        $data = app(EditRejectedMdfeDocument::class)->formData($original);

        $result = app(EditRejectedMdfeDocument::class)->save($original, $data);

        $this->assertSame([['ibge' => '3549805', 'name' => 'São José do Rio Preto']], $result->snapshot['loading_cities']);
        $this->assertSame($original->snapshot['issue_at'], $result->snapshot['issue_at']);
        $this->assertSame($original->mdfe_number, $result->mdfe_number);
        $this->assertSame($original->series, $result->series);
        $this->assertSame($original->access_key, $result->access_key);
        $this->assertSame($original->payload_xml, $result->snapshot['last_rejected_attempt']['payload_xml']);
        $this->assertSame($original->response_xml, $result->snapshot['last_rejected_attempt']['response_xml']);
        $this->assertSame($original->snapshot['loading_cities'], $result->snapshot['last_rejected_attempt']['snapshot']['loading_cities']);
        $this->assertSame($original->payload_xml, $result->payload_xml);
        $this->assertSame($original->response_xml, $result->response_xml);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $result->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();

        $queued = app(EditRejectedMdfeDocument::class)->enqueue($result);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $queued->status);
        $this->assertSame($original->mdfe_number, $queued->mdfe_number);
        $this->assertSame($original->series, $queued->series);
        $this->assertSame($original->access_key, $queued->access_key);
        $this->assertSame($original->snapshot['issue_at'], $queued->snapshot['issue_at']);
        $this->assertSame($original->access_key, $queued->snapshot['last_rejected_attempt']['access_key']);
        $this->assertSame($original->payload_xml, $queued->snapshot['last_rejected_attempt']['payload_xml']);
        $this->assertSame($original->response_xml, $queued->snapshot['last_rejected_attempt']['response_xml']);
        $this->assertNull($queued->payload_xml);
        $this->assertNull($queued->response_xml);
        $this->assertCount(1, Queue::pushed(EmitMdfeDirectJob::class));
        Http::assertNothingSent();
    }

    public function test_edit_form_shows_loading_city_uf_mismatch_before_saving(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $source->ciot->forceFill(['origin' => [
            'cidade' => 'Santo Antônio da Platina', 'uf' => 'SP', 'cep' => '86430000', 'ibge' => '4124103',
        ]])->save();

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->call('save')
            ->assertHasFormErrors(['cte_document_ids']);

        $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
        $this->assertSame('<MDFe>original</MDFe>', $source->fresh()->payload_xml);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_requeues_same_document_number_and_series_preserving_latest_rejection(): void
    {
        $source = $this->rejected();
        MdfeNumberSequence::factory()->create(['cnpj' => '12563112000130', 'series' => 1, 'tp_amb' => 2, 'next_number' => 281]);
        $service = app(EditRejectedMdfeDocument::class);
        $result = $service->enqueue($source);
        $this->assertSame($source->id, $result->id);
        $this->assertSame('280', $result->mdfe_number);
        $this->assertSame('1', $result->series);
        $this->assertSame($source->access_key, $result->access_key);
        $this->assertNull($result->payload_xml);
        $this->assertNull($result->response_xml);
        $this->assertSame('611', $result->snapshot['last_rejected_attempt']['code']);
        $this->assertSame('<MDFe>original</MDFe>', $result->snapshot['last_rejected_attempt']['payload_xml']);
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $result->status);
        $this->assertSame(281, MdfeNumberSequence::query()->first()->next_number);
        $this->assertDatabaseCount('mdfe_documents', 1);
        $this->assertDatabaseCount('dfe_document_reservations', 2);
        Queue::assertPushed(EmitMdfeDirectJob::class, fn ($job) => $job->mdfeDocumentId === $source->id);
    }

    public function test_duplicate_requeue_is_blocked(): void
    {
        $source = $this->rejected();
        app(EditRejectedMdfeDocument::class)->enqueue($source);
        $this->expectException(DomainException::class);
        app(EditRejectedMdfeDocument::class)->enqueue($source);
    }

    public function test_blocks_authorization_uncertain_outcomes_duplicates_and_other_environment(): void
    {
        $source = $this->rejected();
        $service = app(EditRejectedMdfeDocument::class);
        foreach ([
            ['status' => CteDocumentStatusEnum::AUTHORIZED],
            ['status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED],
            ['fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED],
            ['protocol' => '935260123456789'],
            ['fiscal_status_code' => '204'],
            ['fiscal_status_code' => '539'],
        ] as $changes) {
            $candidate = clone $source;
            $candidate->forceFill($changes);
            $this->assertFalse($service->canEdit($candidate));
        }
        config(['dfe.tp_amb' => '1']);
        $this->expectException(DomainException::class);
        $service->enqueue($source);
    }

    public function test_ignores_a_malformed_legacy_plate_override(): void
    {
        $source = $this->rejected();
        $service = app(EditRejectedMdfeDocument::class);
        $data = $service->formData($source);
        $data['vehicle_plate'] = 'INVALID';
        $service->save($source, $data);

        $this->assertSame('BWK4B95', $source->fresh()->snapshot['vehicle']['plate']);
        $this->assertDatabaseCount('mdfe_documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_allows_changing_ctes_and_ciot_with_consistent_keys(): void
    {
        $source = $this->rejected();
        $newCiot = Ciot::factory()->issued()->create();
        $newCte = $this->authorizedCte();
        $service = app(EditRejectedMdfeDocument::class);
        $data = $service->formData($source);
        $data['ciot_id'] = $newCiot->id;
        $data['cte_document_ids'] = [$newCte->id];
        $newSnapshot = $source->snapshot;
        $newSnapshot['ciot'] = $newCiot->ciot_number;
        $newSnapshot['destinations'][0]['cte_access_keys'] = [$newCte->access_key];
        $this->mock(BuildMdfeEmissionSnapshot::class, fn ($mock) => $mock->shouldReceive('handleForDocuments')->once()->andReturn($newSnapshot));
        $result = $service->save($source, $data);
        $this->assertSame($newCiot->id, $result->ciot_id);
        $this->assertSame($newCiot->ciot_number, $result->snapshot['ciot']);
        $this->assertSame([$newCte->id], $result->cteDocuments->modelKeys());
        $this->assertSame([$newCte->access_key], $result->snapshot['destinations'][0]['cte_access_keys']);
    }

    public function test_cannot_reuse_cte_or_ciot_already_authorized_in_another_manifest(): void
    {
        $source = $this->rejected();
        $authorized = MdfeDocument::factory()->create(['status' => CteDocumentStatusEnum::AUTHORIZED, 'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED, 'ciot_id' => $source->ciot_id]);
        $authorized->cteDocuments()->attach($source->cteDocuments->first()->id);
        try {
            app(EditRejectedMdfeDocument::class)->enqueue($source);
            $this->fail('O CIOT já está vinculado a um manifesto autorizado.');
        } catch (DomainException) {
            $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
            $this->assertNotNull($source->fresh()->response_xml);
            $this->assertDatabaseCount('dfe_document_reservations', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_user_reuses_creation_form_with_prefilled_data_then_requeues_from_details(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $this->get(MdfeDocumentResource::getUrl('edit', ['record' => $source]))->assertSuccessful();
        Livewire::test(ViewMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->assertActionVisible('edit')->assertActionVisible('retryEmission')->assertActionDoesNotExist('correctPlate');
        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->assertSuccessful()
            ->assertSet('data.ciot_id', $source->ciot_id)
            ->assertSet('data.cte_document_ids', $source->cteDocuments->modelKeys())
            ->assertSee('CT-es autorizados')
            ->assertDontSee('Placa do MDF-e')
            ->assertDontSee('Municípios de carregamento')
            ->assertDontSee('Municípios de descarregamento e CT-es')
            ->set('data.endorsements', [['number' => 'CORRIGIDA']])
            ->call('save')->assertHasNoFormErrors()->assertRedirect();
        $this->assertSame(['CORRIGIDA'], $source->fresh()->snapshot['insurance']['endorsements']);
        Queue::assertNothingPushed();
        Livewire::test(ViewMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->callAction('retryEmission')->assertHasNoActionErrors()->assertActionHidden('edit');
        $this->assertSame('280', $source->fresh()->mdfe_number);
        Queue::assertPushed(EmitMdfeDirectJob::class, 1);
    }

    public function test_edit_form_offers_creating_a_new_ciot(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $component = Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()]);
        $method = new \ReflectionMethod(EditMdfeDocument::class, 'ciotModeOptions');

        $this->assertArrayHasKey('new', $method->invoke($component->instance()));
    }

    public function test_editing_can_issue_a_replacement_ciot_without_requeuing_the_rejected_mdfe(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
        ]);
        $source = $this->rejected(withBatch: true);
        $oldCiot = $source->ciot;
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->fakeAnttAcceptingTheDeclaration('520032959999');
        $snapshot = $source->snapshot;
        $snapshot['ciot'] = '520032959999';
        $snapshot['vehicle']['plate'] = $vehicle->plate;
        $this->mock(BuildMdfeEmissionSnapshot::class, fn ($mock) => $mock
            ->shouldReceive('handleForDocuments')->once()->andReturn($snapshot));

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->set('data.ciot_mode', 'new')
            ->set('data.new_ciot', $this->newCiotFormData($payer, $vehicle))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $replacement = Ciot::query()->whereKeyNot($oldCiot->id)->latest('id')->firstOrFail();
        $this->assertSame(CiotStatusEnum::ISSUED, $replacement->status);
        $this->assertSame($source->cte_emission_batch_id, $replacement->cte_emission_batch_id);
        $this->assertSame(CiotStatusEnum::ISSUED, $oldCiot->fresh()->status);
        $this->assertSame($replacement->id, $source->fresh()->ciot_id);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
        $this->assertSame('280', $source->fresh()->mdfe_number);
        $this->assertSame('1', $source->fresh()->series);
        $this->assertSame('PUC8E55', $source->fresh()->snapshot['vehicle']['plate']);
        $this->assertDatabaseCount('mdfe_documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_editing_cannot_create_a_replacement_ciot_while_the_batch_is_in_review(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
        ]);
        $source = $this->rejected(withBatch: true);
        $source->batch->forceFill(['status' => CteEmissionBatchStatusEnum::DRAFT])->save();
        $oldCiot = $source->ciot;
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $ciotCount = Ciot::query()->count();
        $snapshot = $source->snapshot;
        $cteDocumentIds = $source->cteDocuments->modelKeys();

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->set('data.ciot_mode', 'new')
            ->set('data.new_ciot', $this->newCiotFormData($payer, $vehicle))
            ->call('save')
            ->assertHasErrors([
                'data.cte_document_ids' => 'O CIOT não pode ser preparado enquanto o lote de CT-e estiver em revisão.',
            ]);

        $this->assertSame($ciotCount, Ciot::query()->count());
        $this->assertSame(CiotStatusEnum::ISSUED, $oldCiot->fresh()->status);
        $this->assertSame($oldCiot->id, $source->fresh()->ciot_id);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
        $this->assertSame($snapshot, $source->fresh()->snapshot);
        $this->assertSame($cteDocumentIds, $source->fresh()->cteDocuments()->orderByPivot('position')->pluck('cte_documents.id')->all());
        $this->assertDatabaseCount('mdfe_documents', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rejected_replacement_ciot_keeps_edit_data_and_the_previous_mdfe_link(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
        ]);
        $source = $this->rejected(withBatch: true);
        $oldCiot = $source->ciot;
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '520032959998'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '217',
                'Mensagem' => 'Dados rejeitados',
                'IdOperacaoTransporte' => '520032959998',
            ], 200),
        ]);

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->set('data.ciot_mode', 'new')
            ->set('data.new_ciot', $this->newCiotFormData($payer, $vehicle))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.ciot_mode', 'new')
            ->assertSet('data.new_ciot.freight_value', '500.00')
            ->assertSet('data.new_ciot.vehicle_ids', [$vehicle->id]);

        $replacement = Ciot::query()->whereKeyNot($oldCiot->id)->latest('id')->firstOrFail();
        $this->assertSame(CiotStatusEnum::FAILED, $replacement->status);
        $this->assertSame($source->cte_emission_batch_id, $replacement->cte_emission_batch_id);
        $this->assertSame($oldCiot->id, $source->fresh()->ciot_id);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
        $this->assertDatabaseCount('mdfe_documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_livewire_can_resolve_the_edit_page_alias_after_loading_its_route(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();

        $this->get(MdfeDocumentResource::getUrl('edit', ['record' => $source]))
            ->assertSuccessful();

        $this->assertSame(
            EditMdfeDocument::class,
            app(ComponentRegistry::class)->getClass('app.filament.resources.mdfe-document-resource.pages.edit-mdfe-document'),
        );
    }

    public function test_authorized_documents_cannot_access_edit_page_or_retry_action(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $source->forceFill(['status' => CteDocumentStatusEnum::AUTHORIZED, 'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED])->save();
        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])->assertForbidden();
        Livewire::test(ViewMdfeDocument::class, ['record' => $source->getRouteKey()])->assertActionHidden('edit')->assertActionHidden('retryEmission');
    }

    public function test_backend_validation_errors_are_shown_on_the_corresponding_edit_field(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->set('data.endorsements', [])
            ->call('save')->assertHasFormErrors(['endorsements']);
        $this->assertSame('BWK4B95', $source->fresh()->snapshot['vehicle']['plate']);
        Queue::assertNothingPushed();
    }

    public function test_editing_inherits_the_exact_creation_form_and_view(): void
    {
        $method = new \ReflectionMethod(EditMdfeDocument::class, 'form');
        $this->assertSame(CreateMdfeDocument::class, $method->getDeclaringClass()->getName());
        $this->assertTrue(is_subclass_of(EditMdfeDocument::class, CreateMdfeDocument::class));
    }

    public function test_alcohol_manifest_prefills_the_saved_driver_in_the_creation_selector(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $driver = MdfeDriver::factory()->create(['line' => CiotLineEnum::TankAlcohol->value, 'cpf' => '12345678909']);
        $source->ciot->forceFill(['line' => CiotLineEnum::TankAlcohol])->save();
        $source->cteDocuments->first()->forceFill(['snapshot' => ['line' => CiotLineEnum::TankAlcohol->value]])->save();
        $snapshot = $source->snapshot;
        $snapshot['line'] = CiotLineEnum::TankAlcohol->value;
        $source->forceFill(['snapshot' => $snapshot])->save();
        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->assertSet('data.driver_id', $driver->id)
            ->call('prepare')->assertHasNoFormErrors()->assertRedirect();
        $this->assertSame($driver->name, $source->fresh()->snapshot['vehicle']['drivers'][0]['name']);
        $this->assertSame('280', $source->fresh()->mdfe_number);
        Queue::assertNothingPushed();
    }

    private function authorizedCte(?int $batchId = null): CteDocument
    {
        return CteDocument::factory()->create([
            'cte_emission_batch_id' => $batchId,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
            'access_key' => str_pad((string) random_int(1, 999999), 44, '1', STR_PAD_LEFT),
            'snapshot' => ['line' => CiotLineEnum::VehicleRemoval->value],
        ]);
    }

    private function rejected(bool $withBatch = false): MdfeDocument
    {
        $batch = $withBatch ? CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]) : null;
        $ciot = Ciot::factory()->issued()->create(['cte_emission_batch_id' => $batch?->id]);
        $cte = $this->authorizedCte($batch?->id);
        $snapshot = [
            'issue_at' => '2026-10-09T12:00:00-03:00', 'tp_emit' => '1', 'ciot' => $ciot->ciot_number,
            'ciot_responsible_cnpj' => '12563112000130', 'rntrc' => '45963122', 'ciot_source' => 'issued', 'line' => CiotLineEnum::VehicleRemoval->value,
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            'loading_cities' => [['ibge' => '3534609', 'name' => 'Osvaldo Cruz']], 'route_ufs' => [],
            'destinations' => [['ibge' => '3508504', 'name' => 'Caçapava', 'cte_access_keys' => [$cte->access_key]]],
            'vehicle' => ['plate' => 'BWK4B95', 'uf' => 'SP', 'renavam' => '12345678901', 'tare_kg' => 8000, 'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'drivers' => [['name' => 'Motorista Fixture', 'cpf' => '12345678909']], 'trailers' => []],
            'cargo' => ['value' => '50000.00', 'weight_kg' => '4000.0000', 'predominant_product' => 'VEICULOS', 'type' => '05'],
            'insurance' => ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'APOLICE-001', 'endorsements' => ['AVER-001'], 'source' => 'configured'],
            'inf_lotacao' => ['loading_cep' => '14810539', 'unloading_cep' => '06550000'],
            'payment' => ['payer_name' => 'COPART', 'payer_cnpj' => '14517191000330', 'components' => [['type' => '04', 'value' => '5000.00']], 'contract_value' => '5000.00', 'method' => '0', 'bank_code' => '104', 'bank_agency' => '0276'],
        ];
        $document = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch?->id, 'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::REJECTED, 'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
            'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1, 'emitter' => 'direct', 'execution_mode' => 'live',
            'mdfe_number' => '280', 'series' => '1',
            'access_key' => app(AccessKey::class)->build('12563112000130', '2610', 1, 280, '58', '1', '12345678'),
            'fiscal_status_code' => '611', 'payload_xml' => '<MDFe>original</MDFe>',
            'response_xml' => '<retMDFe><tpAmb>2</tpAmb><cStat>611</cStat><xMotivo>Rejeição</xMotivo></retMDFe>', 'snapshot' => $snapshot,
        ]);
        $document->cteDocuments()->attach($cte->id, ['position' => 1]);

        return $document;
    }

    /** @return array<string, mixed> */
    private function newCiotFormData(Company $payer, CiotVehicle $vehicle): array
    {
        return [
            'status' => 'draft',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'operation_type' => 'lotation',
            'payer_id' => $payer->id,
            'delivery_payer_id' => $payer->id,
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'distance_km' => '716',
            'freight_value' => '500.00',
            'cargo_weight_kg' => '2000',
            'vehicle_ids' => [$vehicle->id],
            'travel_start_at' => today()->toDateString(),
            'travel_end_at' => today()->addDay()->toDateString(),
        ];
    }

    private function fakeAnttAcceptingTheDeclaration(string $ciotNumber): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => $ciotNumber],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => $ciotNumber.'0001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => $ciotNumber,
            ], 200),
        ]);
    }
}
