<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CityDistance;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDriver;
use App\Services\Ciot\BuildMdfeEmissionSnapshot;
use App\Services\Ciot\CreateCiotForBatch;
use App\Services\Ciot\PrefillCiotFromDocuments;
use App\Services\Ciot\QueueDirectMdfeEmission;
use App\Services\Ciot\ValidateMdfeDocumentSelection;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrepareMdfeFromDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.removal.weight_per_vehicle_kg' => 2000,
            'ciot.distance.api_key' => null,
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.rntrc' => '45963122',
            'dfe.mdfe.insurer_name' => 'Seguradora Fixture',
            'dfe.mdfe.insurer_cnpj' => '11111111111111',
            'dfe.mdfe.policy_number' => 'APOLICE-HML',
            'dfe.mdfe.ncm_vehicle_removal' => '8703.32.10',
            'ciot.lines.vehicle_removal.bank_code' => '033',
            'ciot.lines.vehicle_removal.bank_agency' => '0073',
            'ciot.lines.tank_alcohol.bank_code' => '104',
            'ciot.lines.tank_alcohol.bank_agency' => '0276',
            'dfe.mdfe.ncm_tank_alcohol' => '2207.10.90',
        ]);

        City::factory()->create([
            'ibge_code' => '3534609', 'name' => 'Osvaldo Cruz', 'state' => 'SP',
            'latitude' => -21.7972, 'longitude' => -50.9736,
        ]);
        City::factory()->create([
            'ibge_code' => '3508504', 'name' => 'Caçapava', 'state' => 'SP',
            'latitude' => -23.1006, 'longitude' => -45.6911,
        ]);

        CityDistance::query()->create([
            'origin_ibge' => '3534609',
            'destination_ibge' => '3508504',
            'km' => 716,
            'fetched_at' => now(),
        ]);
    }

    public function test_prefills_from_explicit_documents_across_batches_without_registers(): void
    {
        $payer = Company::factory()->create([
            'name' => 'Copart Caçapava',
            'cnpj' => '14517191000925',
            'city' => 'Caçapava',
            'state' => 'SP',
            'ibge_code' => '3508504',
        ]);
        CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);

        $first = $this->vehicleDocument(CteEmissionBatch::factory()->create(), [
            'value' => '500.00',
            'fipe_value' => '10000.00',
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
        ]);
        $second = $this->vehicleDocument(CteEmissionBatch::factory()->create(), [
            'value' => '400.00',
            'fipe_value' => '9000.00',
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
        ]);

        $prefill = app(PrefillCiotFromDocuments::class)->handle(new Collection([$first, $second]));

        $this->assertSame(CiotLineEnum::VehicleRemoval->value, $prefill['form']['line']);
        $this->assertSame('900.00', $prefill['form']['freight_value']);
        $this->assertSame(4000, $prefill['form']['cargo_weight_kg']);
        $this->assertSame($payer->id, $prefill['form']['payer_id']);
        $this->assertSame('3534609', $prefill['form']['origin.ibge']);
        $this->assertSame(716, $prefill['form']['distance_km']);
        $this->assertStringContainsString('Osvaldo Cruz → Copart Caçapava: 716 km', $prefill['ranking']);
    }

    public function test_rejects_a_malformed_decimal_in_a_cte_snapshot(): void
    {
        $document = $this->vehicleDocument(CteEmissionBatch::factory()->create(), [
            'value' => 'frete inválido',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('valor decimal inválido');

        app(PrefillCiotFromDocuments::class)->handle(new Collection([$document]));
    }

    public function test_rejects_a_selection_that_mixes_modalities(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $documents = new Collection([
            $this->vehicleDocument($batch),
            $this->tankAlcoholDocument($batch),
        ]);
        $ciot = Ciot::factory()->issued()->create(['line' => CiotLineEnum::VehicleRemoval]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modalidades');

        app(ValidateMdfeDocumentSelection::class)->handle($documents, $ciot);
    }

    public function test_rejects_legacy_ctes_even_when_authorized(): void
    {
        $document = $this->vehicleDocument(CteEmissionBatch::factory()->create(), workflowOrigin: null);
        $ciot = Ciot::factory()->issued()->create(['line' => CiotLineEnum::VehicleRemoval]);

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fluxo controlado');

        app(ValidateMdfeDocumentSelection::class)->handle(new Collection([$document]), $ciot);
    }

    public function test_rejects_a_ciot_from_another_modality(): void
    {
        $document = $this->vehicleDocument(CteEmissionBatch::factory()->create());
        $ciot = Ciot::factory()->issued()->tankAlcohol()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('linha do CIOT');

        app(ValidateMdfeDocumentSelection::class)->handle(new Collection([$document]), $ciot);
    }

    public function test_rejects_multiple_alcohol_ctes_in_one_manifest_selection(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $ciot = Ciot::factory()->issued()->tankAlcohol()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exatamente um CT-e');

        app(ValidateMdfeDocumentSelection::class)->handle(
            new Collection([$this->tankAlcoholDocument($batch), $this->tankAlcoholDocument($batch)]),
            $ciot,
        );
    }

    public function test_prefills_alcohol_ciot_from_real_cargo_and_effective_delivery(): void
    {
        $payer = Company::factory()->create([
            'name' => 'Destinatário fiscal',
            'cnpj' => '14517191000925',
            'city' => 'Osvaldo Cruz',
            'state' => 'SP',
            'ibge_code' => '3534609',
        ]);
        $delivery = Company::factory()->create([
            'name' => 'Entrega efetiva',
            'cnpj' => '11222333000181',
            'city' => 'Bauru',
            'state' => 'SP',
            'ibge_code' => '3506003',
        ]);
        City::factory()->create([
            'ibge_code' => '3506003', 'name' => 'Bauru', 'state' => 'SP',
            'latitude' => -22.3145, 'longitude' => -49.0587,
        ]);
        $document = $this->tankAlcoholDocument(CteEmissionBatch::factory()->create());
        $snapshot = $document->snapshot;
        $snapshot['cargo_weight_kg'] = '16023.054';
        $snapshot['delivery_party']['cMun'] = '3506003';
        $snapshot['delivery_party']['xMun'] = 'Bauru';
        $snapshot['delivery_party']['cep'] = '17030000';
        $document->forceFill(['snapshot' => $snapshot])->save();

        $prefill = app(PrefillCiotFromDocuments::class)->handle(new Collection([$document]));

        $this->assertSame(CiotLineEnum::TankAlcohol->value, $prefill['form']['line']);
        $this->assertSame($payer->id, $prefill['form']['payer_id']);
        $this->assertSame($payer->cnpj, $prefill['form']['payer_cnpj']);
        $this->assertSame($delivery->id, $prefill['form']['delivery_payer_id']);
        $this->assertSame($delivery->cnpj, $prefill['form']['delivery_payer_cnpj']);
        $this->assertSame('Bauru', $prefill['form']['destination']['cidade']);
        $this->assertSame('3506003', $prefill['form']['destination']['ibge']);
        $this->assertSame('250.00', $prefill['form']['freight_value']);
        $this->assertSame('16023', $prefill['form']['cargo_weight_kg']);
        $this->assertSame('17030000', $prefill['form']['destination']['cep']);
        $this->assertSame([], $prefill['form']['vehicle_ids']);

        $snapshot['cargo_weight_kg'] = '16023.999';
        $document->forceFill(['snapshot' => $snapshot])->save();

        $prefill = app(PrefillCiotFromDocuments::class)->handle(new Collection([$document]));

        $this->assertSame('16023', $prefill['form']['cargo_weight_kg']);
    }

    public function test_builds_alcohol_mdfe_cargo_from_the_selected_cte_snapshot(): void
    {
        $document = $this->tankAlcoholDocument(CteEmissionBatch::factory()->create());
        $cteSnapshot = $document->snapshot;
        $cteSnapshot['cargo_weight_kg'] = '2000.999';
        $document->forceFill(['snapshot' => $cteSnapshot])->save();

        $ciot = Ciot::factory()->issued()->tankAlcohol()->create([
            'ciot_number' => '520032956638',
            'freight_value_cents' => 25000,
            'cargo_weight_kg' => '9999.99',
            'vehicles' => [['placa' => 'PUC8E55', 'tipo' => 'automotor']],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
        ]);
        CiotVehicle::factory()->forTank()->create([
            'plate' => 'PUC8E55',
            'renavam' => '12345678901',
            'tare_kg' => 8000,
            'capacity_kg' => 12000,
            'type_rod' => '03',
            'body_type' => '00',
            'uf' => 'SP',
        ]);
        $driver = MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::TankAlcohol->value,
        ]);

        $snapshot = app(BuildMdfeEmissionSnapshot::class)->handleForDocuments(
            documents: new Collection([$document]),
            ciot: $ciot,
            endorsements: ['AVERBACAO-ALC-001'],
            routeUfs: [],
            driver: $driver,
            paymentMethod: '0',
        );

        $this->assertSame('10000.00', $snapshot['cargo']['value']);
        $this->assertSame('2000.0000', $snapshot['cargo']['weight_kg']);
        $this->assertSame('2000.999', $document->refresh()->snapshot['cargo_weight_kg']);
        $this->assertNull($snapshot['trip_id']);
    }

    public function test_creates_a_draft_ciot_for_an_explicit_selection_without_a_batch(): void
    {
        $document = $this->vehicleDocument(CteEmissionBatch::factory()->create());
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);

        $ciot = app(CreateCiotForBatch::class)->handleForDocuments(new Collection([$document]), [
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
            'vehicle_ids' => [$tractor->id],
            'travel_start_at' => today()->toDateString(),
            'travel_end_at' => today()->addDay()->toDateString(),
        ]);

        $this->assertNull($ciot->cte_emission_batch_id);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);
        $this->assertSame('PUC8E55', $ciot->vehicles[0]['placa']);
    }

    public function test_rejects_a_vehicle_that_is_not_available_for_the_selected_cte_line(): void
    {
        $document = $this->vehicleDocument(CteEmissionBatch::factory()->create());
        $tankVehicle = CiotVehicle::factory()->forTank()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('disponíveis para a modalidade');

        app(CreateCiotForBatch::class)->handleForDocuments(new Collection([$document]), [
            'line' => CiotLineEnum::VehicleRemoval->value,
            'vehicle_ids' => [$tankVehicle->id],
        ]);
    }

    public function test_queues_an_explicit_cross_batch_selection_with_its_explicit_ciot(): void
    {
        Queue::fake();
        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO']);

        $firstBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        $secondBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        $first = $this->vehicleDocument($firstBatch);
        $second = $this->vehicleDocument($secondBatch, [
            'value' => '250.00',
            'fipe_value' => '5000.00',
        ]);
        $ciot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => null,
            'line' => CiotLineEnum::VehicleRemoval,
            'ciot_number' => '520032956638',
            'freight_value_cents' => 75000,
            'cargo_weight_kg' => '4000.00',
            'vehicles' => [['placa' => 'PUC8E55', 'tipo' => 'automotor']],
            'origin' => [
                'cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '14810539', 'ibge' => '3534609',
            ],
            'destination' => [
                'cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504',
            ],
        ]);
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'renavam' => '12345678901',
            'tare_kg' => 8000,
            'capacity_kg' => 12000,
            'type_rod' => '03',
            'body_type' => '00',
            'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $mdfe = app(QueueDirectMdfeEmission::class)->handleForDocuments(
            documents: new Collection([$first, $second]),
            ciot: $ciot,
            endorsements: ['AVERBACAO-OP-OVERRIDE'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );

        $this->assertNull($mdfe->cte_emission_batch_id);
        $this->assertSame($ciot->id, $mdfe->ciot_id);
        $this->assertSame('PUC8E55', $mdfe->snapshot['vehicle']['plate']);
        $this->assertSame('PUC8E55', $ciot->fresh()->vehicles[0]['placa']);
        $this->assertSame('controlled_v1', $mdfe->workflow_origin->value);
        $this->assertSame([$first->id, $second->id], $mdfe->cteDocuments()->pluck('cte_documents.id')->all());
        $this->assertSame('15000.00', $mdfe->snapshot['cargo']['value']);
        $this->assertNull($mdfe->snapshot['trip_id']);

        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO-NOVA']);

        $this->assertSame(['AVERBACAO-OP-OVERRIDE'], $mdfe->fresh()->snapshot['insurance']['endorsements']);
        $this->assertDatabaseCount('dfe_document_reservations', 3);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        Queue::assertPushed(EmitMdfeDirectJob::class, fn (EmitMdfeDirectJob $job): bool => $job->afterCommit === true);
    }

    private function vehicleDocument(CteEmissionBatch $batch, array $snapshot = [], ?string $workflowOrigin = 'controlled_v1'): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => null,
            'workflow_origin' => $workflowOrigin,
            'snapshot' => array_merge([
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => '500.00',
                'fipe_value' => '10000.00',
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ], $snapshot),
        ]);
    }

    private function tankAlcoholDocument(CteEmissionBatch $batch): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => null,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::TankAlcohol->value,
                'value' => '250.00',
                'cargo_value' => '10000.00',
                'cargo_weight_kg' => '2000.123',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
                'dest' => ['cnpj' => '14517191000925', 'xNome' => 'Destinatário fiscal'],
                'delivery_party' => [
                    'cnpj' => '11222333000181',
                    'xNome' => 'Entrega efetiva',
                    'cMun' => '3508504',
                    'xMun' => 'Caçapava',
                    'uf' => 'SP',
                    'cep' => '12286140',
                ],
            ],
        ]);
    }
}
