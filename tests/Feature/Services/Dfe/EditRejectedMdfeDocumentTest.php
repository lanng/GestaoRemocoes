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
use App\Services\Ciot\CreateCiotForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
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

    public function test_rejected_general_cargo_edit_preserves_explicit_fields_and_can_update_type_using_removal_profile(): void
    {
        $source = $this->rejected();
        $cte = $source->cteDocuments()->firstOrFail();
        $cteSnapshot = $cte->snapshot;
        $cteSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $cte->forceFill(['snapshot' => $cteSnapshot])->save();
        $snapshot = $source->snapshot;
        $snapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $snapshot['cargo'] = [
            'value' => '25000.00',
            'weight_kg' => '1234.5600',
            'predominant_product' => 'PA CARREGADEIRA',
            'type' => '05',
            'ncm' => null,
        ];
        $source->forceFill(['snapshot' => $snapshot])->save();
        $data = app(EditRejectedMdfeDocument::class)->formData($source->fresh());

        $this->assertSame(CiotLineEnum::GeneralCargo->value, $data['line']);
        $this->assertSame([
            'type' => '05',
            'ncm' => '',
            'weight_kg' => '1234.5600',
        ], $data['general_cargo']);
        $data['general_cargo']['type'] = '11';
        $data['general_cargo']['ncm'] = '84295100';
        $rebuiltSnapshot = $source->snapshot;
        $rebuiltSnapshot['cargo']['type'] = '11';
        $rebuiltSnapshot['cargo']['ncm'] = '84295100';
        $this->mock(BuildMdfeEmissionSnapshot::class, fn ($mock) => $mock
            ->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (...$arguments) use ($data): bool {
                $this->assertSame($data['general_cargo'], $arguments[10]);

                return true;
            })
            ->andReturn($rebuiltSnapshot)
            ->shouldReceive('loadingCitiesFromCiotOrigin')
            ->once()
            ->andReturn([['ibge' => '3534609', 'name' => 'Osvaldo Cruz']]));

        $result = app(EditRejectedMdfeDocument::class)->save($source->fresh(), $data);

        $this->assertSame('11', $result->snapshot['cargo']['type']);
        $this->assertSame('84295100', $result->snapshot['cargo']['ncm']);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $result->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unchanged_general_cargo_edit_rebuilds_cargo_and_payment_from_current_sources(): void
    {
        $source = $this->rejected();
        $cte = $source->cteDocuments()->firstOrFail();
        $cteSnapshot = $cte->snapshot;
        $cteSnapshot['schema_version'] = 5;
        $cteSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $cteSnapshot['value'] = '750.00';
        $cteSnapshot['cargo'] = [
            'value' => '25000.00',
            'weight_kg' => '1234.56',
            'predominant_product' => 'PA CARREGADEIRA',
        ];
        $cteSnapshot['cargo_weight_kg'] = '1234.56';
        $cte->forceFill(['snapshot' => $cteSnapshot])->save();

        $storedSnapshot = $source->snapshot;
        $storedSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $storedSnapshot['cargo'] = [
            'value' => '25000.00',
            'weight_kg' => '1234.5600',
            'predominant_product' => 'PA CARREGADEIRA',
            'type' => '05',
            'ncm' => '84295100',
        ];
        $source->forceFill(['snapshot' => $storedSnapshot])->save();
        $data = app(EditRejectedMdfeDocument::class)->formData($source->fresh());
        $rebuiltSnapshot = $storedSnapshot;
        $rebuiltSnapshot['payment']['contract_value'] = '750.00';
        $rebuiltSnapshot['payment']['components'] = [['type' => '04', 'value' => '750.00']];
        $rebuiltSnapshot['cargo']['weight_kg'] = '1234.5600';

        $this->mock(BuildMdfeEmissionSnapshot::class, fn ($mock) => $mock
            ->shouldReceive('handleForDocuments')
            ->once()
            ->andReturn($rebuiltSnapshot)
            ->shouldReceive('loadingCitiesFromCiotOrigin')
            ->once()
            ->andReturn([['ibge' => '3534609', 'name' => 'Osvaldo Cruz']]));

        $result = app(EditRejectedMdfeDocument::class)->save($source->fresh(), $data);

        $this->assertSame('750.00', $result->snapshot['payment']['contract_value']);
        $this->assertSame('750.00', $result->snapshot['payment']['components'][0]['value']);
        $this->assertSame('1234.5600', $result->snapshot['cargo']['weight_kg']);
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

    public function test_general_cargo_rejection_can_issue_a_replacement_general_cargo_ciot_with_removal_profile(): void
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
        $cte = $source->cteDocuments()->firstOrFail();
        $cteSnapshot = $cte->snapshot;
        $cteSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $cteSnapshot['value'] = '500.00';
        $cteSnapshot['cargo'] = [
            'value' => '25000.00',
            'weight_kg' => null,
            'predominant_product' => 'PA CARREGADEIRA',
        ];
        $cteSnapshot['cargo_weight_kg'] = null;
        $cte->forceFill(['snapshot' => $cteSnapshot])->save();
        $snapshot = $source->snapshot;
        $snapshot['line'] = CiotLineEnum::GeneralCargo->value;
        $snapshot['cargo'] = [
            'value' => '25000.00',
            'weight_kg' => null,
            'predominant_product' => 'PA CARREGADEIRA',
            'type' => '11',
            'ncm' => '84295100',
        ];
        $source->forceFill(['snapshot' => $snapshot])->save();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->fakeAnttAcceptingTheDeclaration('520032959999');
        $snapshot['ciot'] = '520032959999';
        $snapshot['vehicle']['plate'] = $vehicle->plate;
        $snapshot['cargo']['weight_kg'] = '1234.5600';
        $newCiotData = $this->newCiotFormData($payer, $vehicle);
        $newCiotData['line'] = CiotLineEnum::GeneralCargo->value;
        $newCiotData['cargo_weight_kg'] = null;
        $this->mock(BuildMdfeEmissionSnapshot::class, fn ($mock) => $mock
            ->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (...$arguments): bool {
                $this->assertSame(CiotLineEnum::GeneralCargo, $arguments[1]->line);
                $this->assertSame('1234.56', (string) $arguments[1]->cargo_weight_kg);
                $this->assertSame(['type' => '11', 'ncm' => '84295100', 'weight_kg' => '1234.56'], $arguments[10]);

                return true;
            })
            ->andReturn($snapshot));

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->set('data.ciot_mode', 'new')
            ->set('data.new_ciot', $newCiotData)
            ->set('data.general_cargo.weight_kg', '1234.56')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $replacement = Ciot::query()->whereKeyNot($oldCiot->id)->latest('id')->firstOrFail();
        $this->assertSame(CiotLineEnum::GeneralCargo, $replacement->line);
        $this->assertSame(CiotStatusEnum::ISSUED, $replacement->status);
        $this->assertSame('1234.56', (string) $replacement->cargo_weight_kg);
        $this->assertSame('PUC8E55', $replacement->vehicles[0]['placa']);
        $this->assertSame(CiotStatusEnum::ISSUED, $oldCiot->fresh()->status);
        $this->assertSame($replacement->id, $source->fresh()->ciot_id);
        $this->assertSame(CiotLineEnum::GeneralCargo->value, $source->fresh()->snapshot['line']);
        $this->assertSame('11', $source->fresh()->snapshot['cargo']['type']);
        $this->assertSame('84295100', $source->fresh()->snapshot['cargo']['ncm']);
        $this->assertSame('1234.5600', $source->fresh()->snapshot['cargo']['weight_kg']);
        $this->assertSame(CteDocumentStatusEnum::REJECTED, $source->fresh()->status);
        $this->assertSame('280', $source->fresh()->mdfe_number);
        $this->assertDatabaseCount('mdfe_documents', 1);
        Queue::assertNothingPushed();
    }

    public function test_invalid_general_cargo_inputs_are_rejected_before_replacement_ciot_creation_or_emission(): void
    {
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
        ]);
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->mock(CreateCiotForBatch::class)
            ->shouldReceive('handleReplacementForDocuments')
            ->andThrow(new DomainException('CIOT creator invoked before general-cargo preflight.'));
        $this->mock(EmitCiotDeclaration::class)->shouldNotReceive('emit');
        $source = $this->rejected();

        $cases = [
            'invalid type' => static function (array &$data, CteDocument $cte): void {
                $data['general_cargo']['type'] = '13';
            },
            'invalid NCM' => static function (array &$data, CteDocument $cte): void {
                $data['general_cargo']['ncm'] = '123';
            },
            'manual weight precision' => static function (array &$data, CteDocument $cte): void {
                $data['general_cargo']['weight_kg'] = '100.201';
            },
            'manual zero weight' => static function (array &$data, CteDocument $cte): void {
                $data['general_cargo']['weight_kg'] = '0.00';
            },
            'new CIOT freight mismatch' => static function (array &$data, CteDocument $cte): void {
                $data['new_ciot']['freight_value'] = '501.00';
            },
            'new CIOT freight precision' => static function (array &$data, CteDocument $cte): void {
                $data['new_ciot']['freight_value'] = '500.001';
            },
            'CT-e freight precision' => static function (array &$data, CteDocument $cte): void {
                $cteSnapshot = $cte->snapshot;
                $cteSnapshot['value'] = '500.001';
                $cte->forceFill(['snapshot' => $cteSnapshot])->save();
            },
            'CT-e cargo value precision' => static function (array &$data, CteDocument $cte): void {
                $cteSnapshot = $cte->snapshot;
                $cteSnapshot['cargo']['value'] = '25000.001';
                $cte->forceFill(['snapshot' => $cteSnapshot])->save();
            },
            'new CIOT weight mismatch' => static function (array &$data, CteDocument $cte): void {
                $data['new_ciot']['cargo_weight_kg'] = '200.30';
            },
            'new CIOT weight precision' => static function (array &$data, CteDocument $cte): void {
                $data['new_ciot']['cargo_weight_kg'] = '100.201';
            },
            'new CIOT missing final weight' => static function (array &$data, CteDocument $cte): void {
                $data['new_ciot']['cargo_weight_kg'] = '0.00';
            },
            'missing CT-e product description' => static function (array &$data, CteDocument $cte): void {
                $cteSnapshot = $cte->snapshot;
                $cteSnapshot['cargo']['predominant_product'] = '';
                $cte->forceFill(['snapshot' => $cteSnapshot])->save();
            },
            'missing CT-e cargo value' => static function (array &$data, CteDocument $cte): void {
                $cteSnapshot = $cte->snapshot;
                $cteSnapshot['cargo']['value'] = '0.00';
                $cte->forceFill(['snapshot' => $cteSnapshot])->save();
            },
        ];

        $errors = [];

        foreach ($cases as $label => $mutate) {
            $cte = $source->cteDocuments()->firstOrFail();
            $cteSnapshot = $cte->snapshot;
            $cteSnapshot['schema_version'] = 5;
            $cteSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
            $cteSnapshot['value'] = '500.00';
            $cteSnapshot['cargo'] = [
                'value' => '25000.00',
                'weight_kg' => '100.20',
                'predominant_product' => 'PA CARREGADEIRA',
            ];
            $cteSnapshot['cargo_weight_kg'] = '100.20';
            $cte->forceFill(['snapshot' => $cteSnapshot])->save();
            $mdfeSnapshot = $source->snapshot;
            $mdfeSnapshot['line'] = CiotLineEnum::GeneralCargo->value;
            $mdfeSnapshot['cargo'] = [
                'value' => '25000.00',
                'weight_kg' => '100.2000',
                'predominant_product' => 'PA CARREGADEIRA',
                'type' => '05',
                'ncm' => '84295100',
            ];
            $source->forceFill(['snapshot' => $mdfeSnapshot])->save();
            $data = app(EditRejectedMdfeDocument::class)->formData($source->fresh());
            $data['line'] = CiotLineEnum::GeneralCargo->value;
            $data['ciot_mode'] = 'new';
            $data['new_ciot'] = array_replace($this->newCiotFormData($payer, $vehicle), [
                'line' => CiotLineEnum::GeneralCargo->value,
                'freight_value' => '500.00',
                'cargo_weight_kg' => '100.20',
            ]);
            $mutate($data, $cte);
            $ciotCount = Ciot::query()->count();

            try {
                app(EditRejectedMdfeDocument::class)->createAndEmitReplacementCiot($source, $data);
                $this->fail("Dados de carga geral inválidos ({$label}) não podem criar ou emitir CIOT substituto.");
            } catch (DomainException $exception) {
                $errors[] = $exception->getMessage();
                $this->assertSame($ciotCount, Ciot::query()->count(), "A validação de {$label} deve ocorrer antes de criar o CIOT substituto.");
            }
        }

        $this->assertCount(count($cases), $errors);
        $this->assertStringNotContainsString('creator invoked before general-cargo preflight', implode(' ', $errors));
        Http::assertNothingSent();
    }

    public function test_replacement_rejects_a_forged_line_before_creating_a_ciot(): void
    {
        $source = $this->rejected();
        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->mock(CreateCiotForBatch::class)
            ->shouldReceive('handleReplacementForDocuments')
            ->andThrow(new DomainException('CIOT creator invoked before line verification.'));
        $this->mock(EmitCiotDeclaration::class)->shouldNotReceive('emit');
        $data = app(EditRejectedMdfeDocument::class)->formData($source);
        $data['ciot_mode'] = 'new';
        $data['line'] = CiotLineEnum::GeneralCargo->value;
        $data['new_ciot'] = $this->newCiotFormData($payer, $vehicle);
        $ciotCount = Ciot::query()->count();

        try {
            app(EditRejectedMdfeDocument::class)->createAndEmitReplacementCiot($source, $data);
            $this->fail('A modalidade informada não pode divergir da modalidade real do CT-e.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('creator invoked before line verification', $exception->getMessage());
            $this->assertSame($ciotCount, Ciot::query()->count());
        }

        Http::assertNothingSent();
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
        $driver = MdfeDriver::query()->where('cpf', '12345678909')->firstOrFail();
        $driver->update(['line' => CiotLineEnum::TankAlcohol->value]);
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

    public function test_rejected_removal_manifest_keeps_its_saved_driver_and_allows_changing_only_that_manifest(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $source = $this->rejected();
        $saved = MdfeDriver::query()->where('cpf', '12345678909')->firstOrFail();
        $fixed = MdfeDriver::factory()->create(['is_default' => true]);
        $selected = MdfeDriver::factory()->create();

        Livewire::test(EditMdfeDocument::class, ['record' => $source->getRouteKey()])
            ->assertFormFieldIsVisible('driver_id')
            ->assertSet('data.driver_id', $saved->id)
            ->set('data.driver_id', $selected->id)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertSame([['name' => $selected->name, 'cpf' => $selected->cpf]], $source->fresh()->snapshot['vehicle']['drivers']);
        $this->assertSame('280', $source->fresh()->mdfe_number);
        $this->assertTrue($fixed->fresh()->is_default);
        $this->assertFalse($selected->fresh()->is_default);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_rejected_removal_manifest_rejects_a_driver_from_another_line_or_inactive(): void
    {
        $source = $this->rejected();
        $service = app(EditRejectedMdfeDocument::class);

        foreach ([['is_active' => false], ['line' => 'tank_alcohol']] as $attributes) {
            $driver = MdfeDriver::factory()->create($attributes);
            $data = $service->formData($source);
            $data['driver_id'] = $driver->id;

            try {
                $service->save($source, $data);
                $this->fail('A edição deve rejeitar um motorista inativo ou de outra modalidade.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('motorista ativo', $exception->getMessage());
            }
        }
    }

    public function test_replacement_ciot_is_not_created_or_emitted_with_an_invalid_driver(): void
    {
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
        ]);
        $source = $this->rejected();
        $service = app(EditRejectedMdfeDocument::class);
        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        $vehicle = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->fakeAnttAcceptingTheDeclaration('520032959999');
        $ciotCount = Ciot::query()->count();

        foreach ([['is_active' => false], ['line' => 'tank_alcohol']] as $attributes) {
            $driver = MdfeDriver::factory()->create($attributes);
            $data = $service->formData($source);
            $data['ciot_mode'] = 'new';
            $data['driver_id'] = $driver->id;
            $data['new_ciot'] = $this->newCiotFormData($payer, $vehicle);

            try {
                $service->createAndEmitReplacementCiot($source, $data);
                $this->fail('O motorista deve ser validado antes de criar ou emitir o CIOT substituto.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('motorista ativo', $exception->getMessage());
            }

            $this->assertDatabaseCount('ciots', $ciotCount);
            Http::assertNothingSent();
        }
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
        MdfeDriver::factory()->create(['name' => 'Motorista Fixture', 'cpf' => '12345678909']);
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
