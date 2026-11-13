<?php

namespace Tests\Feature\Filament;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Filament\Resources\CiotResource\Pages\CreateCiot;
use App\Filament\Resources\CiotResource\Pages\EditCiot;
use App\Filament\Resources\CiotResource\Pages\ListCiots;
use App\Filament\Resources\CiotResource\Pages\ViewCiot;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CityDistance;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class CiotResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
        ]);
    }

    public function test_the_list_shows_ciots_with_badges(): void
    {
        $open = Ciot::factory()->issued()->create(['payer_name' => 'Copart Caçapava']);
        $draft = Ciot::factory()->create(['payer_name' => 'Copart Osasco']);

        Livewire::test(ListCiots::class)
            ->assertCanSeeTableRecords([$open, $draft])
            ->assertSee('Emitido');
    }

    public function test_the_create_form_builds_a_draft_ciot_snapshot(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $delivery = Company::factory()->create(['cnpj' => '14517191000410', 'name' => 'Copart Osasco']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $trailer = CiotVehicle::factory()->trailer()->create(['plate' => 'TIX7D32', 'axles' => 2]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $delivery->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.cep' => '17700000',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '1000.00',
                'cargo_weight_kg' => '20000',
                'vehicle_ids' => [$tractor->id, $trailer->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ciot = Ciot::query()->firstOrFail();

        $this->assertSame(CiotStatusEnum::DRAFT, $ciot->status);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);
        $this->assertSame('Copart Caçapava', $ciot->payer_name);
        $this->assertSame('14517191000410', $ciot->delivery_payer_cnpj);
        $this->assertSame(100000, $ciot->freight_value_cents);
        $this->assertCount(2, $ciot->vehicles);
        $this->assertSame('PUC8E55', $ciot->vehicles[0]['placa']);
        $this->assertNotNull($ciot->public_id);
    }

    public function test_the_create_form_accepts_brazilian_number_formats(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $delivery = Company::factory()->create(['cnpj' => '14517191000410', 'name' => 'Copart Osasco']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $delivery->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.cep' => '17700000',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
                'distance_km' => '716,5',
                'freight_value' => '4.132,98',
                'cargo_weight_kg' => '1.500,50',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ciot = Ciot::query()->firstOrFail();

        $this->assertSame(413298, $ciot->freight_value_cents);
        $this->assertSame('1500.50', (string) $ciot->cargo_weight_kg);
        $this->assertSame('716.50', (string) $ciot->distance_km);
    }

    public function test_the_create_form_rejects_ambiguous_number_formats(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.cep' => '17700000',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '4.132.98',
                'cargo_weight_kg' => '1.500',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasFormErrors(['freight_value']);

        $this->assertDatabaseEmpty(Ciot::class);
    }

    public function test_the_create_form_rejects_a_freight_below_one_cent(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.cep' => '17700000',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '0,00',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->call('create')
            ->assertHasFormErrors(['freight_value']);

        $this->assertDatabaseEmpty(Ciot::class);
    }

    public function test_general_cargo_create_validates_freight_and_weight_precision_before_transforming(): void
    {
        Http::fake();
        $payer = Company::factory()->create();
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        $component = Livewire::test(CreateCiot::class)
            ->fillForm($this->generalCargoFormData($payer, $tractor, [
                'freight_value' => '500,001',
                'cargo_weight_kg' => '1234,567',
            ]))
            ->assertSet('data.line', CiotLineEnum::GeneralCargo->value)
            ->assertSet('data.freight_value', '500,001')
            ->assertSet('data.cargo_weight_kg', '1234,567')
            ->call('create');

        $component->assertHasFormErrors(['freight_value', 'cargo_weight_kg']);
        $this->assertDatabaseEmpty(Ciot::class);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), rtrim(config('ciot.base_url'), '/')));
    }

    public function test_general_cargo_create_requires_a_positive_weight(): void
    {
        Http::fake();
        $payer = Company::factory()->create();
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        foreach ([null, '0,00'] as $weight) {
            Livewire::test(CreateCiot::class)
                ->fillForm($this->generalCargoFormData($payer, $tractor, ['cargo_weight_kg' => $weight]))
                ->call('create')
                ->assertHasFormErrors(['cargo_weight_kg']);
        }

        $this->assertDatabaseEmpty(Ciot::class);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), rtrim(config('ciot.base_url'), '/')));
    }

    public function test_general_cargo_edit_validates_freight_and_weight_precision_without_mutating_the_ciot(): void
    {
        Http::fake();
        $ciot = Ciot::factory()->create([
            'line' => CiotLineEnum::GeneralCargo,
            'freight_value_cents' => 50000,
            'cargo_weight_kg' => '1234.56',
        ]);

        Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->fillForm([
                'freight_value' => '500,001',
                'cargo_weight_kg' => '1234,567',
            ])
            ->call('save')
            ->assertHasFormErrors(['freight_value', 'cargo_weight_kg']);

        $this->assertSame(50000, $ciot->fresh()->freight_value_cents);
        $this->assertSame('1234.56', (string) $ciot->fresh()->cargo_weight_kg);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), rtrim(config('ciot.base_url'), '/')));
    }

    public function test_general_cargo_create_accepts_trailing_zero_padding_and_saves_exact_two_decimal_values(): void
    {
        $payer = Company::factory()->create();
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        Livewire::test(CreateCiot::class)
            ->fillForm($this->generalCargoFormData($payer, $tractor, [
                'freight_value' => '500.0000',
                'cargo_weight_kg' => '1234.5600',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $ciot = Ciot::query()->firstOrFail();

        $this->assertSame(50000, $ciot->freight_value_cents);
        $this->assertSame('1234.56', (string) $ciot->cargo_weight_kg);
    }

    public function test_the_emit_action_enqueues_the_emission(): void
    {
        Queue::fake();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '560000569998'],
            ], 200),
        ]);

        $ciot = Ciot::factory()->create();

        Livewire::test(ListCiots::class)
            ->callTableAction('emit', $ciot);

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);
        Queue::assertPushed(EmitCiotJob::class);
    }

    public function test_the_cancel_action_requires_and_records_a_reason(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'Codigo' => '110',
                'Mensagem' => 'Cancelado',
                'DataCancelamento' => '2026-09-22T10:00:00-03:00',
            ], 200),
        ]);

        Livewire::test(ListCiots::class)
            ->callTableAction('cancel', $ciot, data: ['motivo' => 'Carga não ocorreu'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CiotStatusEnum::CANCELED, $ciot->refresh()->status);
        $this->assertSame('Carga não ocorreu', $ciot->cancel_reason);
    }

    public function test_the_view_page_shows_the_number_and_carrier_notice(): void
    {
        $ciot = Ciot::factory()->issued()->create([
            'verifier_code' => '1234',
            'carrier_notice' => 'Aviso importante ao transportador',
        ]);

        Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->assertSee($ciot->ciot_number)
            ->assertSee($ciot->fullNumber())
            ->assertSee('Aviso importante ao transportador');
    }

    public function test_the_cep_field_autocompletes_city_state_and_ibge(): void
    {
        Http::fake([
            'https://brasilapi.com.br/api/cep/v2/12286140' => Http::response([
                'state' => 'SP',
                'city' => 'Caçapava',
                'ibge_code' => '3508504',
                'location' => [
                    'coordinates' => ['latitude' => '-23.1006', 'longitude' => '-45.6911'],
                ],
            ], 200),
        ]);

        Livewire::test(CreateCiot::class)
            ->fillForm(['origin.cep' => '12286140'])
            ->assertFormSet([
                'origin.cidade' => 'Caçapava',
                'origin.uf' => 'SP',
                'origin.ibge' => '3508504',
            ]);
    }

    public function test_the_distance_action_fills_the_km_from_openrouteservice(): void
    {
        config(['ciot.distance.api_key' => 'test-key']);

        $origin = City::factory()->create([
            'ibge_code' => '3534609',
            'name' => 'Osvaldo Cruz',
            'state' => 'SP',
            'latitude' => -21.7972,
            'longitude' => -50.9736,
        ]);
        $destination = City::factory()->create([
            'ibge_code' => '3508504',
            'name' => 'Caçapava',
            'state' => 'SP',
            'latitude' => -23.1006,
            'longitude' => -45.6911,
        ]);

        Http::fake([
            'https://api.openrouteservice.org/v2/directions/driving-car' => Http::response([
                'routes' => [
                    ['summary' => ['distance' => 716254.8, 'duration' => 32100.0]],
                ],
            ], 200),
        ]);

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'origin.ibge' => $origin->ibge_code,
                'destination.ibge' => $destination->ibge_code,
            ])
            ->callFormComponentAction('distance_km', 'calcularDistancia')
            ->assertHasNoFormComponentActionErrors()
            ->assertFormSet(['distance_km' => '716,00']);

        $this->assertDatabaseHas(CityDistance::class, [
            'origin_ibge' => $origin->ibge_code,
            'destination_ibge' => $destination->ibge_code,
            'km' => 716,
        ]);
    }

    public function test_the_distance_action_warns_when_coordinates_are_missing(): void
    {
        $origin = City::factory()->withoutCoordinates()->create();
        $destination = City::factory()->create();

        Livewire::test(CreateCiot::class)
            ->fillForm([
                'origin.ibge' => $origin->ibge_code,
                'destination.ibge' => $destination->ibge_code,
            ])
            ->callFormComponentAction('distance_km', 'calcularDistancia')
            ->assertFormComponentActionExists('distance_km', 'calcularDistancia');
    }

    public function test_create_and_emit_issues_the_ciot_synchronously(): void
    {
        Queue::fake();

        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => '520032959999'],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => '5200329599990001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => '520032959999',
            ], 200),
        ]);

        Livewire::test(CreateCiot::class)
            ->assertSee('Criar e emitir')
            ->fillForm([
                'line' => 'vehicle_removal',
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $payer->id,
                'origin.cidade' => 'Osvaldo Cruz',
                'origin.uf' => 'SP',
                'origin.ibge' => '3534609',
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.ibge' => '3508504',
                'distance_km' => '716',
                'freight_value' => '1000.00',
                'vehicle_ids' => [$tractor->id],
                'travel_start_at' => now()->addDay()->format('Y-m-d'),
                'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
            ])
            ->call('createAndEmit')
            ->assertNotified('CIOT emitido: 5200329599990001');

        $ciot = Ciot::query()->firstOrFail();

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032959999', $ciot->ciot_number);
        $this->assertSame('0001', $ciot->verifier_code);

        Queue::assertNothingPushed();
    }

    public function test_save_and_emit_issues_the_ciot_synchronously(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttAcceptingTheDeclaration('520032959997');

        Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->call('saveAndEmit')
            ->assertNotified('CIOT emitido: 5200329599970001');

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_save_and_emit_warns_when_the_antt_does_not_respond(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttQueueingTheDeclaration('520032959997');

        $component = Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->call('saveAndEmit');

        $this->assertNotificationSent(
            'CIOT salvo — emissão em processamento',
            'A ANTT não respondeu agora; a emissão ficou na fila com retry automático.',
            'warning',
        );

        $component->assertNotified('CIOT salvo — emissão em processamento');

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);

        Queue::assertPushed(EmitCiotJob::class);
    }

    public function test_save_and_emit_notifies_the_antt_rejection(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttRejectingTheDeclaration('520032959997');

        $component = Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->call('saveAndEmit');

        $this->assertNotificationSent('Emissão rejeitada pela ANTT', 'Contratante bloqueado', 'danger');

        $component->assertNotified('Emissão rejeitada pela ANTT');

        $this->assertSame(CiotStatusEnum::FAILED, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_save_and_emit_notifies_an_invalid_ciot(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response('CNPJ nao autorizado', 400),
        ]);

        $component = Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->call('saveAndEmit');

        $this->assertNotificationSent(
            'Falha ao emitir o CIOT',
            'Geração de IdOperacaoTransporte falhou (HTTP 400): CNPJ nao autorizado',
            'danger',
        );

        $component->assertNotified('Falha ao emitir o CIOT');

        $this->assertSame(CiotStatusEnum::DRAFT, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_the_view_emit_action_issues_the_ciot_synchronously(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttAcceptingTheDeclaration('520032959997');

        Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->callAction('emit')
            ->assertNotified('CIOT emitido: 5200329599970001');

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_the_view_emit_action_warns_when_the_antt_does_not_respond(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttQueueingTheDeclaration('520032959997');

        $component = Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->callAction('emit');

        $this->assertNotificationSent(
            'Emissão em processamento',
            'A ANTT não respondeu agora; a emissão ficou na fila com retry automático.',
            'warning',
        );

        $component->assertNotified('Emissão em processamento');

        $this->assertSame(CiotStatusEnum::PENDING, $ciot->refresh()->status);

        Queue::assertPushed(EmitCiotJob::class);
    }

    public function test_the_view_emit_action_notifies_the_antt_rejection(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        $this->fakeAnttRejectingTheDeclaration('520032959997');

        $component = Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->callAction('emit');

        $this->assertNotificationSent('Emissão rejeitada pela ANTT', 'Contratante bloqueado', 'danger');

        $component->assertNotified('Emissão rejeitada pela ANTT');

        $this->assertSame(CiotStatusEnum::FAILED, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_the_view_emit_action_notifies_an_invalid_ciot(): void
    {
        Queue::fake();

        $ciot = $this->editableCiot();

        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response('CNPJ nao autorizado', 400),
        ]);

        $component = Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->callAction('emit');

        $this->assertNotificationSent(
            'Falha ao emitir o CIOT',
            'Geração de IdOperacaoTransporte falhou (HTTP 400): CNPJ nao autorizado',
            'danger',
        );

        $component->assertNotified('Falha ao emitir o CIOT');

        $this->assertSame(CiotStatusEnum::DRAFT, $ciot->refresh()->status);

        Queue::assertNothingPushed();
    }

    public function test_edit_updates_a_failed_ciot_without_recreating_it(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::FAILED,
            'payer_id' => $payer->id,
            'payer_cnpj' => $payer->cnpj,
            'payer_name' => $payer->name,
            'delivery_payer_id' => $payer->id,
            'delivery_payer_cnpj' => $payer->cnpj,
            'delivery_payer_name' => $payer->name,
            'vehicles' => [['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor']],
            'freight_value_cents' => 100000,
        ]);

        $this->assertTrue(CiotResource::canEdit($ciot));
        $this->assertFalse(CiotResource::canEdit(Ciot::factory()->issued()->create()));

        Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->fillForm(['distance_km' => '250'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('250.00', $ciot->refresh()->distance_km);
        $this->assertSame('100000', (string) $ciot->freight_value_cents);
        $this->assertSame(CiotStatusEnum::FAILED, $ciot->status);
    }

    public function test_editing_prunes_the_payer_from_additional_payers_b119(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        Company::factory()->create(['cnpj' => '14517191000410']);
        CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        $ciot = Ciot::factory()->create([
            'status' => CiotStatusEnum::DRAFT,
            'operation_type' => \App\Enums\CiotOperationTypeEnum::Fractioned,
            'payer_id' => $payer->id,
            'payer_cnpj' => $payer->cnpj,
            'payer_name' => $payer->name,
            'delivery_payer_id' => $payer->id,
            'delivery_payer_cnpj' => $payer->cnpj,
            'delivery_payer_name' => $payer->name,
            'additional_payers' => ['14517191000925', '14517191000410'],
            'vehicles' => [['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor']],
            'freight_value_cents' => 100000,
        ]);

        Livewire::test(EditCiot::class, ['record' => $ciot->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['14517191000410'], $ciot->refresh()->additional_payers);
    }

    public function test_the_view_page_renders_a_record_with_nested_responses(): void
    {
        $ciot = Ciot::factory()->issued()->create([
            'status' => CiotStatusEnum::CLOSED,
            'closed_at' => now(),
            'response' => [
                'Codigo' => '110',
                'Mensagem' => 'Advertência: distância inferior ao calculado.',
                'Protocolo' => '5200329566385921',
                'CodigoVerificador' => '5921',
                'AvisoTransportador' => null,
                'IdOperacaoTransporte' => '520032956638',
                'encerramento' => [
                    'CodigoIdentificacaoOperacao' => '5200329566385921',
                    'DataEncerramento' => '2026-09-22T17:20:02',
                    'Codigo' => '110',
                    'Protocolo' => 'T98000002282767',
                ],
            ],
        ]);

        Livewire::test(ViewCiot::class, ['record' => $ciot->id])
            ->assertSuccessful()
            ->assertSee('T98000002282767');
    }

    public function test_the_cancel_action_shows_a_formal_notification_on_antt_rejection(): void
    {
        $ciot = Ciot::factory()->issued()->create();

        Http::fake([
            'https://antt-hml.test/pefServices/api/CancelamentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => $ciot->fullNumber(),
                'DataCancelamento' => null,
                'Codigo' => '220',
                'Mensagem' => '["Rejeição: Nao foi encontrada nenhuma Operaçao de Transporte com os dados informados."]',
            ], 200),
        ]);

        Livewire::test(ListCiots::class)
            ->callTableAction('cancel', $ciot, data: ['motivo' => 'motivo de teste'])
            ->assertNotified('Falha ao cancelar o CIOT');

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->refresh()->status);
    }

    public function test_selecting_the_delivery_payer_autocompletes_the_destination(): void
    {
        $payer = Company::factory()->create([
            'name' => 'Copart Caçapava',
            'cnpj' => '14517191000925',
            'city' => 'Caçapava',
            'state' => 'SP',
            'zipcode' => '12286140',
            'ibge_code' => '3508504',
        ]);

        Livewire::test(CreateCiot::class)
            ->fillForm(['delivery_payer_id' => $payer->id])
            ->assertFormSet([
                'destination.cidade' => 'Caçapava',
                'destination.uf' => 'SP',
                'destination.cep' => '12286140',
                'destination.ibge' => '3508504',
            ]);
    }

    public function test_the_navigation_badge_counts_open_ciots(): void
    {
        Ciot::factory()->issued()->create();
        Ciot::factory()->issued()->create();
        Ciot::factory()->create();

        $this->assertSame('2', CiotResource::getNavigationBadge());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function generalCargoFormData(Company $payer, CiotVehicle $tractor, array $overrides = []): array
    {
        return array_replace([
            'line' => CiotLineEnum::GeneralCargo->value,
            'operation_type' => 'lotation',
            'payer_id' => $payer->id,
            'delivery_payer_id' => $payer->id,
            'origin.cidade' => 'Osvaldo Cruz',
            'origin.uf' => 'SP',
            'origin.cep' => '17700000',
            'origin.ibge' => '3534609',
            'destination.cidade' => 'Caçapava',
            'destination.uf' => 'SP',
            'destination.cep' => '12286140',
            'destination.ibge' => '3508504',
            'distance_km' => '716',
            'freight_value' => '500.00',
            'cargo_weight_kg' => '1234.56',
            'vehicle_ids' => [$tractor->id],
            'travel_start_at' => now()->addDay()->format('Y-m-d'),
            'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
        ], $overrides);
    }

    /**
     * Confirma título, corpo e status da notificação enviada (o
     * assertNotified do Filament cobre apenas o título).
     */
    protected function assertNotificationSent(string $title, ?string $body, string $status): void
    {
        $notification = collect(session('filament.notifications', []))
            ->first(fn (array $sent): bool => $sent['title'] === $title);

        $this->assertNotNull($notification, "A notificação '{$title}' não foi enviada.");
        $this->assertSame($body, $notification['body']);
        $this->assertSame($status, $notification['status']);
    }

    /**
     * Rascunho hidratável pelo formulário de edição: pagante e veículo
     * existem como registros, então o save() valida e o emit() tem payload.
     */
    protected function editableCiot(): Ciot
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925']);
        CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);

        return Ciot::factory()->create([
            'payer_id' => $payer->id,
            'payer_cnpj' => $payer->cnpj,
            'payer_name' => $payer->name,
            'delivery_payer_id' => $payer->id,
            'delivery_payer_cnpj' => $payer->cnpj,
            'delivery_payer_name' => $payer->name,
            'vehicles' => [['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor']],
            'freight_value_cents' => 100000,
        ]);
    }

    /**
     * Stub do caminho feliz: gerador de IdOperacaoTransporte, token e
     * declaração aceita com protocolo de 16 dígitos.
     */
    protected function fakeAnttAcceptingTheDeclaration(string $idOperacaoTransporte): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => $idOperacaoTransporte],
            ], 200),
            'https://antt-hml.test/pefServices/token' => Http::response(['token' => 'tok'], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '110',
                'Mensagem' => 'Dados cadastrados com sucesso',
                'Protocolo' => $idOperacaoTransporte.'0001',
                'CodigoVerificador' => '0001',
                'IdOperacaoTransporte' => $idOperacaoTransporte,
            ], 200),
        ]);
    }

    /**
     * Stub do fallback: prepare aceito, declaração recusada (500 retryable) —
     * a emissão fica PENDING com o job na fila.
     */
    protected function fakeAnttQueueingTheDeclaration(string $idOperacaoTransporte): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => $idOperacaoTransporte],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response('boom', 500),
        ]);
    }

    /**
     * Stub da rejeição de negócio: declaração respondida com Código 999.
     */
    protected function fakeAnttRejectingTheDeclaration(string $idOperacaoTransporte): void
    {
        Http::fake([
            'https://antt-hml.test/pefServices/gerar' => Http::response([
                'Sucesso' => true,
                'Dados' => ['CIOT' => $idOperacaoTransporte],
            ], 200),
            'https://antt-hml.test/pefServices/api/DeclaracaoOperacaoTransporte' => Http::response([
                'Codigo' => '999',
                'Mensagem' => 'Contratante bloqueado',
            ], 200),
        ]);
    }
}
