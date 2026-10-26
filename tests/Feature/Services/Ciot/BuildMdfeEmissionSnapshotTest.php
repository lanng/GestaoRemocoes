<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDriver;
use App\Services\Ciot\BuildMdfeEmissionSnapshot;
use App\Services\Ciot\QueueDirectMdfeEmission;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BuildMdfeEmissionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.rntrc' => '45963122',
            'dfe.mdfe.insurer_name' => 'Seguradora Fixture',
            'dfe.mdfe.insurer_cnpj' => '11111111111111',
            'dfe.mdfe.policy_number' => 'APOLICE-HML',
            'dfe.mdfe.ncm_vehicle_removal' => '8703.32.10',
            'dfe.mdfe.ncm_tank_alcohol' => '2207.10.90',
            'ciot.lines.vehicle_removal.bank_code' => '033',
            'ciot.lines.vehicle_removal.bank_agency' => '0073',
            'ciot.lines.tank_alcohol.bank_code' => '104',
            'ciot.lines.tank_alcohol.bank_agency' => '0276',
        ]);
    }

    public function test_builds_snapshot_from_issued_ciot_authorized_cte_catalogued_vehicle_and_default_driver(): void
    {
        [$batch, $ciot] = $this->makeTrip();
        $ciot->forceFill(['freight_value_cents' => 230000])->save();
        $ciot->forceFill(['origin' => [
            'cidade' => 'São José do Rio Preto', 'uf' => 'SP', 'cep' => '15000000', 'ibge' => '3549805',
        ]])->save();
        $cte = $batch->documents()->firstOrFail();
        $cteSnapshot = $cte->snapshot;
        $cteSnapshot['origin'] = ['ibge' => '4124103', 'name' => 'Santo Antônio da Platina', 'uf' => 'PR'];
        $cte->forceFill(['snapshot' => $cteSnapshot])->save();
        $vehicle = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'renavam' => '12345678901',
            'tare_kg' => 8000,
            'capacity_kg' => 12000,
            'type_rod' => '03',
            'body_type' => '00',
            'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'name' => 'Motorista Cadastro',
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $snapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
            paymentMethod: '0',
        );

        $this->assertSame($ciot->ciot_number, $snapshot['ciot']);
        $this->assertSame('PUC8E55', $snapshot['vehicle']['plate']);
        $this->assertSame($vehicle->renavam, $snapshot['vehicle']['renavam']);
        $this->assertSame([['name' => 'Motorista Cadastro', 'cpf' => '12345678909']], $snapshot['vehicle']['drivers']);
        $this->assertSame('AVERBACAO-OP-001', $snapshot['insurance']['endorsements'][0]);
        $this->assertSame('VEICULOS', $snapshot['cargo']['predominant_product']);
        $this->assertSame('05', $snapshot['cargo']['type']);
        $this->assertSame('35260912563112000130570010000028171839975374', $batch->documents()->first()->access_key);
        $this->assertSame($batch->documents()->first()->access_key, $snapshot['destinations'][0]['cte_access_keys'][0]);
        $this->assertSame('50000.00', $snapshot['cargo']['value']);
        $this->assertSame([['ibge' => '3549805', 'name' => 'São José do Rio Preto']], $snapshot['loading_cities']);
        $this->assertSame([
            'loading_cep' => '15000000',
            'unloading_cep' => '06550000',
        ], $snapshot['inf_lotacao']);
        $this->assertSame([
            'payer_name' => $ciot->payer_name,
            'payer_cnpj' => $ciot->payer_cnpj,
            'components' => [['type' => '04', 'value' => '2300.00']],
            'contract_value' => '2300.00',
            'method' => '0',
            'bank_code' => '033',
            'bank_agency' => '0073',
        ], $snapshot['payment']);
    }

    public function test_mismatched_ciot_origin_is_rejected_before_mdfe_queue_and_reservation(): void
    {
        Queue::fake();
        [$batch, $ciot] = $this->makeTrip();
        $ciot->forceFill(['origin' => [
            'cidade' => 'Santo Antônio da Platina', 'uf' => 'SP', 'cep' => '86430000', 'ibge' => '4124103',
        ]])->save();
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909', 'line' => CiotLineEnum::VehicleRemoval->value, 'is_default' => true,
        ]);

        try {
            app(QueueDirectMdfeEmission::class)->handle(
                batch: $batch,
                endorsements: ['AVERBACAO-OP-001'],
                routeUfs: [],
            );
            $this->fail('Mismatched CIOT origin must not be queued for MDF-e emission.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Santo Antônio da Platina', $exception->getMessage());
        }

        $this->assertDatabaseCount('mdfe_documents', 0);
        $this->assertDatabaseCount('dfe_document_reservations', 0);
        Queue::assertNothingPushed();
    }

    public function test_selects_the_configured_ncm_by_line_in_homologation_and_production_snapshots(): void
    {
        [$batch] = $this->makeTrip();
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);
        $homologationSnapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );

        config(['dfe.tp_amb' => '1']);

        $productionSnapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );

        $this->assertSame('87033210', $homologationSnapshot['cargo']['ncm']);
        $this->assertSame('87033210', $productionSnapshot['cargo']['ncm']);
    }

    public function test_uses_configured_bank_details_and_defaults_to_the_confirmed_payment_method(): void
    {
        [$batch, $ciot] = $this->makeTrip();
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $snapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
        );

        $this->assertSame('0', $snapshot['payment']['method']);
        $this->assertSame('033', $snapshot['payment']['bank_code']);
        $this->assertSame('0073', $snapshot['payment']['bank_agency']);
        $this->assertSame($ciot->payer_name, $snapshot['payment']['payer_name']);
    }

    public function test_rejects_a_failed_ciot_217_in_production_as_well(): void
    {
        [$batch, $ciot] = $this->makeTrip();
        $ciot->forceFill([
            'status' => 'failed',
            'ciot_number' => null,
            'error_code' => '217',
            'payload' => ['DadosCarga' => ['CodigoNaturezaCarga' => 1, 'CodigoTipoCarga' => 5]],
        ])->save();
        config(['dfe.tp_amb' => '1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CIOT emitido não encontrado para este lote.');

        app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['99032'],
            routeUfs: [],
        );
    }

    public function test_requires_manual_interstate_route_ufs_and_tank_driver_selection(): void
    {
        [$batch] = $this->makeTrip(line: CiotLineEnum::TankAlcohol, destinationUf: 'PR');
        CiotVehicle::factory()->forTank()->create([
            'plate' => 'PUC8E55',
            'renavam' => '12345678901',
            'tare_kg' => 8000,
            'capacity_kg' => 12000,
            'type_rod' => '03',
            'body_type' => '00',
            'uf' => 'SP',
        ]);
        $driver = MdfeDriver::factory()->create(['line' => CiotLineEnum::TankAlcohol->value]);

        try {
            app(BuildMdfeEmissionSnapshot::class)->handle($batch, ['AVERBACAO-OP-001'], ['MT']);
            $this->fail('Deveria exigir motorista selecionado para tanque.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('motorista', mb_strtolower($exception->getMessage()));
        }

        try {
            app(BuildMdfeEmissionSnapshot::class)->handle($batch, ['AVERBACAO-OP-001'], [], $driver);
            $this->fail('Deveria exigir UFs intermediárias.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('UFs intermediárias', $exception->getMessage());
        }

        $snapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: ['MT'],
            driver: $driver,
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );

        $this->assertSame($driver->cpf, $snapshot['vehicle']['drivers'][0]['cpf']);
        $this->assertSame(['MT'], $snapshot['route_ufs']);
        $this->assertSame('ALCOOL ETILICO', $snapshot['cargo']['predominant_product']);
        $this->assertSame('08', $snapshot['cargo']['type']);
        $this->assertSame('22071090', $snapshot['cargo']['ncm']);
        $this->assertSame('104', $snapshot['payment']['bank_code']);
        $this->assertSame('0276', $snapshot['payment']['bank_agency']);
    }

    public function test_uses_ciots_location_ceps_for_single_cte_lotation_and_omits_lotation_for_multiple_ctes(): void
    {
        [$singleCteBatch, $ciot] = $this->makeTrip();
        $origin = $ciot->origin;
        $destination = $ciot->destination;
        $origin['cep'] = '';
        $destination['cep'] = '';
        $ciot->forceFill(['origin' => $origin, 'destination' => $destination])->save();

        try {
            app(BuildMdfeEmissionSnapshot::class)->handle($singleCteBatch, ['AVERBACAO-OP-001'], []);
            $this->fail('Deveria exigir os CEPs dos locais efetivos para um único CT-e.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('CEP', $exception->getMessage());
        }

        $origin['cep'] = '14810A39';
        $destination['cep'] = '06550000';
        $ciot->forceFill(['origin' => $origin, 'destination' => $destination])->save();

        try {
            app(BuildMdfeEmissionSnapshot::class)->handle(
                batch: $singleCteBatch,
                endorsements: ['AVERBACAO-OP-001'],
                routeUfs: [],
            );
            $this->fail('Deveria rejeitar CEP com caracteres não numéricos.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('CEP', $exception->getMessage());
        }

        $document = $singleCteBatch->documents()->firstOrFail();
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $singleCteBatch->id,
            'access_key' => '35261012563112000130570019000000061960595781',
            'workflow_origin' => 'controlled_v1',
            'snapshot' => $document->snapshot,
        ]);
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $multipleCteSnapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $singleCteBatch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
        );

        $this->assertNull($multipleCteSnapshot['inf_lotacao']);
        $this->assertNotNull($multipleCteSnapshot['payment']);
    }

    public function test_direct_mdfe_preparation_rejects_source_batches_in_review_with_or_without_batch_context(): void
    {
        foreach ([true, false] as $passBatchContext) {
            $batch = CteEmissionBatch::factory()->create();
            $document = CteDocument::factory()->authorized()->create([
                'cte_emission_batch_id' => $batch->id,
                'workflow_origin' => 'controlled_v1',
            ]);
            $ciot = Ciot::factory()->issued()->create(['cte_emission_batch_id' => null]);

            try {
                app(QueueDirectMdfeEmission::class)->handleForDocuments(
                    documents: new \Illuminate\Database\Eloquent\Collection([$document]),
                    ciot: $ciot,
                    endorsements: ['AVERBACAO-TEST'],
                    routeUfs: [],
                    batch: $passBatchContext ? $batch : null,
                );
                $this->fail('A source batch in draft review must block MDF-e preparation.');
            } catch (DomainException $exception) {
                $this->assertSame('O MDF-e não pode ser preparado enquanto o lote de CT-e estiver em revisão.', $exception->getMessage());
            }
        }
    }

    public function test_builds_payment_from_ciot_and_sums_ctes_when_ciot_freight_is_missing(): void
    {
        [$batch, $ciot] = $this->makeTrip();
        $ciot->forceFill(['freight_value_cents' => 0])->save();
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => '35260912563112000130570010000028171839975375',
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => '2000.00',
                'fipe_value' => '25000.00',
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'DEF4G56',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '4106902', 'name' => 'Curitiba', 'uf' => 'SP'],
            ],
        ]);
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $snapshot = app(BuildMdfeEmissionSnapshot::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
        );

        $this->assertSame($ciot->payer_name, $snapshot['payment']['payer_name']);
        $this->assertSame(preg_replace('/\D/', '', (string) $ciot->payer_cnpj), $snapshot['payment']['payer_cnpj']);
        $this->assertSame('3000.00', $snapshot['payment']['contract_value']);
        $this->assertSame('3000.00', $snapshot['payment']['components'][0]['value']);
        $this->assertSame('033', $snapshot['payment']['bank_code']);
        $this->assertSame('0073', $snapshot['payment']['bank_agency']);
    }

    public function test_queues_one_direct_mdfe_only_after_its_snapshot_is_complete(): void
    {
        Queue::fake();

        [$batch] = $this->makeTrip();
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $document = app(QueueDirectMdfeEmission::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-001'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );

        $this->assertSame('direct', $document->emitter);
        $this->assertSame('controlled_v1', $document->getRawOriginal('workflow_origin'));
        $this->assertSame('live', $document->execution_mode);
        $this->assertSame('VEICULOS', $document->snapshot['cargo']['predominant_product']);
        $this->assertSame('05', $document->snapshot['cargo']['type']);
        Queue::assertPushed(EmitMdfeDirectJob::class, fn (EmitMdfeDirectJob $job): bool => $job->afterCommit === true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Um ou mais CT-es já estão vinculados a outro MDF-e não cancelado.');

        app(QueueDirectMdfeEmission::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-002'],
            routeUfs: [],
        );
    }

    public function test_direct_queue_rejects_authorized_ctes_from_the_legacy_workflow(): void
    {
        [$batch] = $this->makeTrip(controlledWorkflow: false);
        CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55', 'renavam' => '12345678901', 'tare_kg' => 8000,
            'capacity_kg' => 12000, 'type_rod' => '03', 'body_type' => '00', 'uf' => 'SP',
        ]);
        MdfeDriver::factory()->create([
            'cpf' => '12345678909',
            'line' => CiotLineEnum::VehicleRemoval->value,
            'is_default' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('somente CT-es do fluxo controlado pelo emissor direto');

        app(QueueDirectMdfeEmission::class)->handle(
            batch: $batch,
            endorsements: ['AVERBACAO-OP-LEGADO'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '104',
            paymentBankAgency: '0276',
        );
    }

    /** @return array{CteEmissionBatch, Ciot} */
    private function makeTrip(
        CiotLineEnum $line = CiotLineEnum::VehicleRemoval,
        string $destinationUf = 'SP',
        bool $controlledWorkflow = true,
    ): array {
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $ciot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => $batch->id,
            'line' => $line,
            'vehicles' => [['placa' => 'PUC8E55', 'tipo' => 'automotor']],
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '14810539', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Curitiba', 'uf' => $destinationUf, 'cep' => '06550000', 'ibge' => '4106902'],
            'cargo_weight_kg' => '4000.00',
        ]);
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => '35260912563112000130570010000028171839975374',
            'workflow_origin' => $controlledWorkflow ? 'controlled_v1' : null,
            'snapshot' => [
                'schema_version' => 2,
                'line' => $line->value,
                'value' => '1000.00',
                'fipe_value' => '50000.00',
                'cargo_value' => '50000.00',
                'cargo_weight_kg' => '4000.000',
                'vehicle_model' => $line === CiotLineEnum::TankAlcohol ? 'ETANOL' : 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '4106902', 'name' => 'Curitiba', 'uf' => $destinationUf],
            ],
        ]);

        return [$batch, $ciot];
    }
}
