<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Jobs\EmitCiotJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\CreateCiotForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Ciot\PrepareMdfeDocument;
use App\Services\Ciot\QueueDirectMdfeEmission;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrepareMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private MdfeDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '756',
            'ciot.lines.vehicle_removal.bank_agency' => '0001',
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
        ]);

        $this->driver = MdfeDriver::factory()->create();
    }

    public function test_ignores_legacy_plate_override_before_validating_the_selected_ctes(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Selecione ao menos um CT-e autorizado.');

        app(PrepareMdfeDocument::class)->handle(['vehicle_plate' => 'INVALID']);
    }

    public function test_preparation_passes_the_selected_removal_driver_to_the_mdfe_queue(): void
    {
        $document = $this->controlledVehicleDocument();
        $ciot = Ciot::factory()->issued()->create();
        $selected = MdfeDriver::factory()->create();
        $mdfe = MdfeDocument::factory()->create(['ciot_id' => $ciot->id]);
        $this->mock(QueueDirectMdfeEmission::class)
            ->shouldReceive('handleForDocuments')->once()
            ->withArgs(fn ($documents, $chosenCiot, $endorsements, $routeUfs, $batch, $driver): bool => $driver?->is($selected) === true)
            ->andReturn($mdfe);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'existing',
            'ciot_id' => $ciot->id,
            'driver_id' => $selected->id,
            'endorsements' => [['number' => 'AVER-001']],
        ]);

        $this->assertTrue($result->mdfe->is($mdfe));
    }

    public function test_preparation_passes_explicit_general_cargo_classification_to_the_mdfe_queue(): void
    {
        $document = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'schema_version' => 5,
                'line' => CiotLineEnum::GeneralCargo->value,
                'value' => '500.00',
                'cargo' => [
                    'value' => '25000.00',
                    'predominant_product' => 'PA CARREGADEIRA',
                    'weight_kg' => '1234.56',
                ],
                'cargo_weight_kg' => '1234.56',
            ],
        ]);
        $ciot = Ciot::factory()->issued()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'freight_value_cents' => 50000,
            'cargo_weight_kg' => '1234.56',
        ]);
        $driver = MdfeDriver::factory()->create(['line' => CiotLineEnum::VehicleRemoval->value]);
        $mdfe = MdfeDocument::factory()->create(['ciot_id' => $ciot->id]);

        $this->mock(QueueDirectMdfeEmission::class)
            ->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (...$arguments) use ($driver): bool {
                $this->assertSame($driver->id, $arguments[5]->id);
                $this->assertSame([
                    'type' => '05',
                    'ncm' => '84295100',
                    'weight_kg' => null,
                ], $arguments[10]);

                return true;
            })
            ->andReturn($mdfe);

        app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'existing',
            'ciot_id' => $ciot->id,
            'driver_id' => $driver->id,
            'endorsements' => [['number' => 'AVER-001']],
            'general_cargo' => [
                'type' => '05',
                'ncm' => '84295100',
                'weight_kg' => null,
            ],
        ]);
    }

    public function test_invalid_general_cargo_inputs_are_rejected_before_initial_ciot_creation_or_emission(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->mock(CreateCiotForBatch::class)
            ->shouldReceive('handleForDocuments')
            ->andThrow(new DomainException('CIOT creator invoked before general-cargo preflight.'));
        $this->mock(EmitCiotDeclaration::class)->shouldNotReceive('emit');

        $cases = [
            'invalid type' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['general_cargo']['type'] = '13';
            },
            'invalid NCM' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['general_cargo']['ncm'] = '123';
            },
            'manual weight precision' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['general_cargo']['weight_kg'] = '100.201';
            },
            'manual zero weight' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['general_cargo']['weight_kg'] = '0.00';
            },
            'new CIOT freight mismatch' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['new_ciot']['freight_value'] = '751.00';
            },
            'new CIOT freight precision' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['new_ciot']['freight_value'] = '500.001';
            },
            'CT-e freight precision' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $snapshot['value'] = '500.001';
            },
            'CT-e cargo value precision' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $cargo['value'] = '25000.001';
                $snapshot['cargo'] = $cargo;
            },
            'new CIOT weight mismatch' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['new_ciot']['cargo_weight_kg'] = '200.30';
            },
            'new CIOT weight precision' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['new_ciot']['cargo_weight_kg'] = '100.201';
            },
            'new CIOT missing final weight' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $data['new_ciot']['cargo_weight_kg'] = '0.00';
                $data['general_cargo']['weight_kg'] = '100.20';
            },
            'missing CT-e product description' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $cargo['predominant_product'] = '';
                $snapshot['cargo'] = $cargo;
            },
            'missing CT-e cargo value' => static function (array &$snapshot, array &$cargo, array &$data): void {
                $cargo['value'] = '0.00';
                $snapshot['cargo'] = $cargo;
            },
        ];

        $errors = [];

        foreach ($cases as $label => $mutate) {
            $document = $this->generalCargoDocument();
            $snapshot = $document->snapshot;
            $cargo = $snapshot['cargo'];
            $data = [
                'line' => CiotLineEnum::GeneralCargo->value,
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'driver_id' => $this->driver->id,
                'new_ciot' => array_replace($this->newCiotFormData($payer, $tractor), [
                    'line' => CiotLineEnum::GeneralCargo->value,
                    'freight_value' => '500.00',
                    'cargo_weight_kg' => '100.20',
                ]),
                'general_cargo' => [
                    'type' => '05',
                    'ncm' => '84295100',
                    'weight_kg' => null,
                ],
                'endorsements' => [['number' => 'AVER-GERAL-001']],
            ];
            $mutate($snapshot, $cargo, $data);
            $document->forceFill(['snapshot' => $snapshot])->save();
            $ciotCount = Ciot::query()->count();

            try {
                app(PrepareMdfeDocument::class)->handle($data);
                $this->fail("Dados de carga geral inválidos ({$label}) não podem criar ou emitir CIOT.");
            } catch (DomainException $exception) {
                $errors[] = $exception->getMessage();
                $this->assertSame($ciotCount, Ciot::query()->count(), "A validação de {$label} deve ocorrer antes de criar o CIOT.");
            }
        }

        $this->assertCount(count($cases), $errors);
        $this->assertStringNotContainsString('creator invoked before general-cargo preflight', implode(' ', $errors));
    }

    public function test_supplied_line_must_match_the_selected_cte_before_initial_ciot_creation(): void
    {
        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->mock(CreateCiotForBatch::class)
            ->shouldReceive('handleForDocuments')
            ->andThrow(new DomainException('CIOT creator invoked before line verification.'));
        $this->mock(EmitCiotDeclaration::class)->shouldNotReceive('emit');
        $ciotCount = Ciot::query()->count();

        try {
            app(PrepareMdfeDocument::class)->handle([
                'line' => CiotLineEnum::GeneralCargo->value,
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'driver_id' => $this->driver->id,
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => 'AVER-001']],
            ]);
            $this->fail('A modalidade enviada não pode divergir da modalidade real do CT-e.');
        } catch (DomainException $exception) {
            $this->assertStringNotContainsString('creator invoked before line verification', $exception->getMessage());
            $this->assertSame($ciotCount, Ciot::query()->count());
        }
    }

    public function test_existing_general_cargo_ciot_is_checked_against_cte_weight_before_queueing(): void
    {
        $document = $this->generalCargoDocument();
        $ciot = Ciot::factory()->issued()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'freight_value_cents' => 50000,
            'cargo_weight_kg' => '200.30',
        ]);
        $this->mock(QueueDirectMdfeEmission::class)->shouldNotReceive('handleForDocuments');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'line' => CiotLineEnum::GeneralCargo->value,
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'existing',
                'ciot_id' => $ciot->id,
                'driver_id' => $this->driver->id,
                'endorsements' => [['number' => 'AVER-GERAL-002']],
                'general_cargo' => ['type' => '05', 'ncm' => null, 'weight_kg' => null],
            ]);
            $this->fail('O peso do CIOT existente deve corresponder ao peso do CT-e antes de enfileirar.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('peso', mb_strtolower($exception->getMessage()));
        }
    }

    public function test_new_general_cargo_ciot_data_is_normalized_from_cte_weight_when_missing(): void
    {
        $preparedData = app(\App\Services\Ciot\ResolveGeneralCargoMdfeCargo::class)->prepareNewCiotData(
            $this->generalCargoDocument(),
            ['type' => '05', 'ncm' => null, 'weight_kg' => null],
            ['freight_value' => '500.00', 'cargo_weight_kg' => null],
        );

        $this->assertSame('100.20', $preparedData['cargo_weight_kg']);
    }

    public function test_existing_general_cargo_ciot_without_positive_weight_is_rejected_without_mutation(): void
    {
        $document = $this->generalCargoDocument();
        $this->mock(QueueDirectMdfeEmission::class)->shouldNotReceive('handleForDocuments');

        foreach ([null, '0.00'] as $weight) {
            $ciot = Ciot::factory()->issued()->create([
                'line' => CiotLineEnum::GeneralCargo,
                'freight_value_cents' => 50000,
                'cargo_weight_kg' => $weight,
            ]);
            $before = $ciot->getRawOriginal('cargo_weight_kg');

            try {
                app(PrepareMdfeDocument::class)->handle([
                    'line' => CiotLineEnum::GeneralCargo->value,
                    'cte_document_ids' => [$document->id],
                    'ciot_mode' => 'existing',
                    'ciot_id' => $ciot->id,
                    'driver_id' => $this->driver->id,
                    'endorsements' => [['number' => 'AVER-GERAL-004']],
                    'general_cargo' => ['type' => '05', 'ncm' => null, 'weight_kg' => null],
                ]);
                $this->fail('Um CIOT já emitido sem peso positivo não deve ser alterado nem reutilizado.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('crie um novo ciot compatível', mb_strtolower($exception->getMessage()));
                $freshCiot = $ciot->fresh();
                $after = $freshCiot->getRawOriginal('cargo_weight_kg');
                $this->assertSame($before === null, $after === null);
                $this->assertEquals((float) $before, (float) $after);
                $this->assertSame(CiotStatusEnum::ISSUED, $freshCiot->status);
            }
        }

        $this->assertSame(2, Ciot::query()->count());
        $this->assertSame(0, MdfeDocument::query()->count());
    }

    public function test_preparation_rejects_missing_or_invalid_removal_drivers_before_any_ciot_emission(): void
    {
        Http::fake();
        $document = $this->controlledVehicleDocument();
        MdfeDriver::factory()->create(['is_default' => true]);
        $inactive = MdfeDriver::factory()->create(['is_active' => false]);
        $tankDriver = MdfeDriver::factory()->create(['line' => 'tank_alcohol']);

        foreach ([null, 999999, $inactive->id, $tankDriver->id] as $driverId) {
            try {
                app(PrepareMdfeDocument::class)->handle([
                    'cte_document_ids' => [$document->id],
                    'ciot_mode' => 'new',
                    'driver_id' => $driverId,
                    'endorsements' => [['number' => 'AVER-001']],
                ]);
                $this->fail('A preparação deve exigir um motorista ativo da modalidade selecionada.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('motorista ativo', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ciots', 0);
    }

    public function test_it_emits_a_new_ciot_before_queuing_the_mdfe(): void
    {
        Queue::fake();

        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);

        $this->fakeAnttAcceptingTheDeclaration('520032959999');

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (Collection $documents, Ciot $ciot, array $endorsements, array $routeUfs): bool {
                $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
                $this->assertCount(1, $documents);
                $this->assertSame(['AVERBACAO-001'], $endorsements);
                $this->assertSame([], $routeUfs);

                return true;
            })
            ->andReturnUsing(fn (Collection $documents, Ciot $ciot): MdfeDocument => MdfeDocument::factory()->create([
                'cte_emission_batch_id' => null,
                'ciot_id' => $ciot->id,
            ]));
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'new',
            'driver_id' => $this->driver->id,
            'new_ciot' => $this->newCiotFormData($payer, $tractor),
            'endorsements' => [['number' => 'AVERBACAO-001']],
        ]);

        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);
        $this->assertNotNull($result->mdfe);
        $this->assertSame($result->ciot->id, $result->mdfe->ciot_id);
        Queue::assertNothingPushed();
    }

    public function test_new_general_cargo_uses_manual_weight_when_cte_and_new_ciot_weights_are_missing(): void
    {
        Queue::fake();
        $document = $this->generalCargoDocument();
        $snapshot = $document->snapshot;
        $snapshot['cargo']['weight_kg'] = null;
        $snapshot['cargo_weight_kg'] = null;
        $document->forceFill(['snapshot' => $snapshot])->save();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create(['plate' => 'PUC8E55']);
        $this->fakeAnttAcceptingTheDeclaration('520032959997');

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldReceive('handleForDocuments')
            ->once()
            ->withArgs(function (Collection $documents, Ciot $ciot, array $endorsements, array $routeUfs): bool {
                $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
                $this->assertSame('100.20', (string) $ciot->cargo_weight_kg);
                $this->assertCount(1, $documents);

                return true;
            })
            ->andReturnUsing(fn (Collection $documents, Ciot $ciot): MdfeDocument => MdfeDocument::factory()->create([
                'ciot_id' => $ciot->id,
                'snapshot' => ['cargo' => ['weight_kg' => '100.2000']],
            ]));
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $newCiotData = array_replace($this->newCiotFormData($payer, $tractor), [
            'line' => CiotLineEnum::GeneralCargo->value,
            'freight_value' => '500.00',
            'cargo_weight_kg' => null,
        ]);
        $result = app(PrepareMdfeDocument::class)->handle([
            'line' => CiotLineEnum::GeneralCargo->value,
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'new',
            'driver_id' => $this->driver->id,
            'new_ciot' => $newCiotData,
            'general_cargo' => [
                'type' => '05',
                'ncm' => null,
                'weight_kg' => '100.20',
            ],
            'endorsements' => [['number' => 'AVER-GERAL-003']],
        ]);

        $this->assertSame(CiotStatusEnum::ISSUED, $result->ciot->status);
        $this->assertSame('100.20', (string) $result->ciot->fresh()->cargo_weight_kg);
        $this->assertSame('100.2000', $result->mdfe->snapshot['cargo']['weight_kg']);
        $this->assertSame($result->ciot->id, $result->mdfe->ciot_id);
    }

    public function test_it_does_not_queue_an_mdfe_when_the_new_ciot_is_rejected(): void
    {
        Queue::fake();

        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);

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

        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $result = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$document->id],
            'ciot_mode' => 'new',
            'driver_id' => $this->driver->id,
            'new_ciot' => $this->newCiotFormData($payer, $tractor),
            'endorsements' => [['number' => 'AVERBACAO-001']],
        ]);

        $this->assertSame(CiotStatusEnum::FAILED, $result->ciot->status);
        $this->assertNull($result->mdfe);
        $this->assertSame(0, MdfeDocument::query()->count());
        Queue::assertNotPushed(EmitCiotJob::class);
    }

    public function test_it_rejects_legacy_cte_ids_before_creating_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument(workflowOrigin: null);
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fluxo controlado');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'driver_id' => $this->driver->id,
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => 'AVERBACAO-001']],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_rejects_an_overlong_endorsement_before_emitting_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede 40 caracteres');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => str_repeat('A', 41)]],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_requires_an_endorsement_before_emitting_a_new_ciot(): void
    {
        $document = $this->controlledVehicleDocument();
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->forRemoval()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
        ]);
        $queueService = \Mockery::mock(QueueDirectMdfeEmission::class);
        $queueService->shouldNotReceive('handleForDocuments');
        $this->app->instance(QueueDirectMdfeEmission::class, $queueService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('averbação');

        try {
            app(PrepareMdfeDocument::class)->handle([
                'cte_document_ids' => [$document->id],
                'ciot_mode' => 'new',
                'new_ciot' => $this->newCiotFormData($payer, $tractor),
                'endorsements' => [['number' => '']],
            ]);
        } finally {
            $this->assertSame(0, Ciot::query()->count());
        }
    }

    public function test_it_lists_only_eligible_ctes_in_the_approved_order_and_open_ciots(): void
    {
        $date = now()->subDay();
        $numberNine = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '9',
            'access_key' => str_repeat('9', 44),
            'authorized_at' => $date,
        ]);
        $numberTen = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '10',
            'access_key' => str_repeat('8', 44),
            'authorized_at' => $date,
        ]);
        $numberEleven = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '11',
            'access_key' => str_repeat('7', 44),
            'authorized_at' => $date,
        ]);
        $legacy = $this->controlledVehicleDocument(workflowOrigin: null, overrides: [
            'cte_number' => '12',
            'access_key' => str_repeat('6', 44),
            'authorized_at' => $date->copy()->addDay(),
        ]);
        $queued = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '13',
            'access_key' => str_repeat('5', 44),
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $lineMismatch = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '14',
            'access_key' => str_repeat('4', 44),
            'snapshot' => [
                'line' => CiotLineEnum::TankAlcohol->value,
            ],
        ]);

        $activeCiot = Ciot::factory()->issued()->create();
        $activeMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $activeCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $activeMdfe->cteDocuments()->attach($numberNine->id, ['position' => 1]);

        $olderCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date,
        ]);
        $newerCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date->copy()->addDay(),
        ]);
        $closedCiot = Ciot::factory()->create([
            'status' => CiotStatusEnum::CLOSED,
        ]);
        $tankAlcoholCiot = Ciot::factory()->issued()->tankAlcohol()->create();
        $activeCiot->forceFill(['issued_at' => $date->copy()->addDays(2)])->save();
        $resumableCiot = Ciot::factory()->issued()->create([
            'issued_at' => $date->copy()->subDay(),
        ]);
        $rejectedMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $resumableCiot->id,
            'status' => CteDocumentStatusEnum::REJECTED,
        ]);
        $rejectedMdfe->cteDocuments()->attach($numberNine->id, ['position' => 1]);

        $ctes = app(PrepareMdfeDocument::class)->eligibleCteDocuments(CiotLineEnum::VehicleRemoval);
        $ciots = app(PrepareMdfeDocument::class)->eligibleCiots(CiotLineEnum::VehicleRemoval);

        $this->assertSame([$numberEleven->id, $numberTen->id], $ctes->pluck('id')->all());
        $this->assertNotContains($legacy->id, $ctes->pluck('id')->all());
        $this->assertNotContains($queued->id, $ctes->pluck('id')->all());
        $this->assertNotContains($lineMismatch->id, $ctes->pluck('id')->all());
        $this->assertSame([$newerCiot->id, $olderCiot->id, $resumableCiot->id], $ciots->pluck('id')->all());
        $this->assertNotContains($activeCiot->id, $ciots->pluck('id')->all());
        $this->assertNotContains($closedCiot->id, $ciots->pluck('id')->all());
        $this->assertNotContains($tankAlcoholCiot->id, $ciots->pluck('id')->all());
    }

    public function test_only_ctes_linked_to_canceled_mdfes_are_eligible_and_limit_is_applied_after_filters(): void
    {
        $service = app(PrepareMdfeDocument::class);
        $date = now()->subDay();
        $available = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '901',
            'access_key' => str_pad('901', 44, '0', STR_PAD_LEFT),
            'authorized_at' => $date,
        ]);
        $canceled = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '902',
            'access_key' => str_pad('902', 44, '0', STR_PAD_LEFT),
            'authorized_at' => $date->copy()->addMinute(),
        ]);
        $linkedToCanceled = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::CANCELLED,
        ]);
        $linkedToCanceled->cteDocuments()->attach($canceled->id, ['position' => 1]);

        $canceledByCode = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '909',
            'access_key' => str_pad('909', 44, '0', STR_PAD_LEFT),
            'authorized_at' => $date->copy()->addMinutes(2),
        ]);
        $linkedToCanceledByCode = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '101',
        ]);
        $linkedToCanceledByCode->cteDocuments()->attach($canceledByCode->id, ['position' => 1]);

        $canceledByStatus = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '910',
            'access_key' => str_pad('910', 44, '0', STR_PAD_LEFT),
            'authorized_at' => $date->copy()->addMinutes(3),
        ]);
        $linkedToCanceledByStatus = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::CANCELLED,
            'fiscal_status' => null,
        ]);
        $linkedToCanceledByStatus->cteDocuments()->attach($canceledByStatus->id, ['position' => 1]);

        foreach ([
            ['status' => CteDocumentStatusEnum::QUEUED, 'fiscal_status_code' => null],
            ['status' => CteDocumentStatusEnum::AUTHORIZED, 'fiscal_status_code' => '132'],
            ['status' => CteDocumentStatusEnum::REJECTED, 'fiscal_status_code' => null],
            ['status' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, 'fiscal_status_code' => null],
        ] as $index => $attributes) {
            $document = $this->controlledVehicleDocument(overrides: [
                'cte_number' => (string) (903 + $index),
                'access_key' => str_pad((string) (903 + $index), 44, '0', STR_PAD_LEFT),
                'authorized_at' => $date->copy()->addMinutes(2 + $index),
            ]);
            $mdfe = MdfeDocument::factory()->create($attributes);
            $mdfe->cteDocuments()->attach($document->id, ['position' => 1]);
        }

        $mixed = $this->controlledVehicleDocument(overrides: [
            'cte_number' => '908',
            'access_key' => str_pad('908', 44, '0', STR_PAD_LEFT),
            'authorized_at' => $date->copy()->addMinutes(10),
        ]);
        $mixedCanceled = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::CANCELLED,
        ]);
        $mixedCanceled->cteDocuments()->attach($mixed->id, ['position' => 1]);
        $mixedOpen = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $mixedOpen->cteDocuments()->attach($mixed->id, ['position' => 1]);

        $this->assertSame([$canceledByStatus->id, $canceledByCode->id, $canceled->id, $available->id], $service
            ->eligibleCteDocuments(CiotLineEnum::VehicleRemoval)
            ->pluck('id')->all());

        $matching = collect();
        for ($number = 920; $number < 925; $number++) {
            $matching->push($this->controlledVehicleDocument(overrides: [
                'cte_number' => (string) $number,
                'access_key' => str_pad((string) $number, 44, '0', STR_PAD_LEFT),
                'authorized_at' => $date->copy()->addMinutes($number - 919),
            ]));
        }

        $limited = $service->eligibleCteDocuments(CiotLineEnum::VehicleRemoval, search: '92', limit: 2);

        $this->assertSame([$matching[4]->id, $matching[3]->id], $limited->pluck('id')->all());
    }

    public function test_batch_context_scopes_search_and_selected_cte_labels(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $otherBatch = CteEmissionBatch::factory()->create();
        $inside = $this->controlledVehicleDocument(overrides: [
            'cte_emission_batch_id' => $batch->id,
            'cte_number' => '101',
            'access_key' => str_repeat('1', 44),
        ]);
        $outside = $this->controlledVehicleDocument(overrides: [
            'cte_emission_batch_id' => $otherBatch->id,
            'cte_number' => '102',
            'access_key' => str_repeat('2', 44),
        ]);

        $service = app(PrepareMdfeDocument::class);

        $this->assertSame(
            [$inside->id],
            $service->eligibleCteDocuments(CiotLineEnum::VehicleRemoval, batchId: $batch->id)->pluck('id')->all(),
        );
        $this->assertSame(
            [$inside->id],
            $service->eligibleCteDocumentsByIds(CiotLineEnum::VehicleRemoval, [$outside->id, $inside->id], $batch->id)->pluck('id')->all(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function controlledVehicleDocument(?string $workflowOrigin = 'controlled_v1', array $overrides = []): CteDocument
    {
        return CteDocument::factory()->authorized()->create(array_merge([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'workflow_origin' => $workflowOrigin,
            'access_key' => str_repeat('1', 44),
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => '500.00',
                'fipe_value' => '10000.00',
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            ],
        ], $overrides));
    }

    private function generalCargoDocument(): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'workflow_origin' => 'controlled_v1',
            'access_key' => str_pad((string) random_int(1, 999999), 44, '7', STR_PAD_LEFT),
            'snapshot' => [
                'schema_version' => 5,
                'line' => CiotLineEnum::GeneralCargo->value,
                'value' => '500.00',
                'cargo' => [
                    'value' => '25000.00',
                    'predominant_product' => 'PA CARREGADEIRA',
                    'weight_kg' => '100.20',
                ],
                'cargo_weight_kg' => '100.20',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function newCiotFormData(Company $payer, CiotVehicle $tractor): array
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
            'vehicle_ids' => [$tractor->id],
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
