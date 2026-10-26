<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Jobs\EmitMdfeDirectJob;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\CityDistance;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\AnttCiotClient;
use App\Services\Ciot\AnttCiotResponse;
use App\Services\Ciot\CancelCiot;
use App\Services\Ciot\PrepareMdfeDocument;
use App\Services\Ciot\QueueDirectMdfeEmission;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\EmitCteDocument;
use App\Services\Cte\EmitMdfeDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use App\Services\Dfe\CancelCteDocument;
use App\Services\Dfe\CancelMdfeDocument;
use App\Services\Dfe\CloseTransportOperation;
use App\Services\Dfe\MdfeSoapClient;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazResponse;
use App\Services\Dfe\SefazSoapClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\BuildsCteWorld;
use Tests\Support\DfeSoapResponses;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class IndependentFiscalEmissionFlowTest extends TestCase
{
    use BuildsCteWorld;
    use RefreshDatabase;

    private string $cteBaseUrl = 'https://sefaz-hml.test/CTeWS/WS';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 10:00:00-03:00');
        config([
            'ciot.api_key' => 'test-api-key',
            'ciot.base_url' => 'https://antt-hml.test/pefServices',
            'ciot.company.cnpj' => '12563112000130',
            'ciot.company.rntrc' => '45963122',
            'ciot.lines.vehicle_removal.bank_code' => '033',
            'ciot.lines.vehicle_removal.bank_agency' => '0073',
            'ciot.lines.vehicle_removal.bank_account' => '123456',
            'ciot.lines.tank_alcohol.bank_code' => '104',
            'ciot.lines.tank_alcohol.bank_agency' => '0276',
            'ciot.lines.tank_alcohol.bank_account' => '123456',
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.base_url' => $this->cteBaseUrl,
            'dfe.cert_path' => null,
            'dfe.cert_password' => null,
            'dfe.ca_bundle' => '/path/that/does/not/exist/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
            'dfe.xsd_path' => resource_path('xsds/cte'),
            'dfe.numbering_start' => 900000001,
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.emit.ie' => '150046654114',
            'dfe.emit.xNome' => 'FERNANDES & FERNANDES LTDA',
            'dfe.emit.xFant' => 'AUTO SOCORRO ESTRELA',
            'dfe.emit.xLgr' => 'RUA PRUDENTE DE MORAES',
            'dfe.emit.nro' => '130',
            'dfe.emit.xBairro' => 'PQ RES ITAMARATI',
            'dfe.emit.cMun' => '3500105',
            'dfe.emit.xMun' => 'Adamantina',
            'dfe.emit.cep' => '17800000',
            'dfe.emit.uf' => 'SP',
            'dfe.cte.serie' => '1',
            'dfe.cte.modal' => '01',
            'dfe.cte.rntrc' => '45963122',
            'dfe.mdfe' => [
                'emitter' => 'direct',
                'version' => '3.00',
                'series' => '1',
                'tp_emit' => '1',
                'tp_emis' => '1',
                'rntrc' => '45963122',
                'numbering_start' => 900000021,
                'xsd_path' => resource_path('xsds/mdfe'),
                'qr_base_url' => 'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode',
                'insurer_name' => 'Seguradora Fixture',
                'insurer_cnpj' => '11111111111111',
                'policy_number' => 'APOLICE-HML',
                'production_enabled' => false,
                'ncm_tank_alcohol' => '2207.10.90',
                'paths' => [
                    'recepcao_sinc' => '/ws/MDFeRecepcaoSinc/MDFeRecepcaoSinc.asmx',
                    'consulta' => '/ws/MDFeConsulta/MDFeConsulta.asmx',
                    'evento' => '/ws/MDFeRecepcaoEvento/MDFeRecepcaoEvento.asmx',
                ],
            ],
        ]);

        $certificate = DfeTestCertificate::create();
        config([
            'dfe.cert_path' => $certificate['path'],
            'dfe.cert_password' => $certificate['password'],
        ]);
        app()->instance(SefazCertificate::class, new SefazCertificate($certificate['path'], $certificate['password']));

        Storage::fake('dfe');
        Http::preventStrayRequests();
        Queue::fake();
        $this->seedCteWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_multiple_nfe_alcohol_flows_through_cte_ciot_mdfe_and_coordinated_closure(): void
    {
        DfeSoapResponses::fakeOnline($this->cteBaseUrl);
        DfeSoapResponses::fakeTransmission($this->cteBaseUrl);

        $cte = app(CreateAlcoholCteDocument::class)->handle(
            files: [
                $this->fixture('nfe-delivery-001.xml', 'entrada-1.xml'),
                $this->fixture('nfe-delivery-002.xml', 'entrada-2.xml'),
            ],
            freightValue: '450.00',
            deliveryDate: '2026-10-10',
        );
        $cte = app(QueueCteDocumentEmission::class)->handle($cte);

        $this->assertSame(CteDocumentStatusEnum::QUEUED, $cte->status);
        Queue::assertPushed(EmitCteDirectJob::class, fn (EmitCteDirectJob $job): bool => $job->cteDocumentId === $cte->id);

        (new EmitCteDirectJob($cte->id))->handle(app(EmitCteDocument::class));

        $cte = $cte->refresh();
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $cte->status);
        $this->assertSame(FiscalDocumentStatusEnum::AUTHORIZED, $cte->fiscal_status);
        $this->assertSame('3000.00', $cte->snapshot['cargo_value']);
        $this->assertSame('300.0000', $cte->snapshot['cargo_liters']);
        $this->assertSame('240.000', $cte->snapshot['cargo_weight_kg']);
        $this->assertSame('22222222222222', $cte->snapshot['dest']['cnpj']);
        $this->assertSame('33333333333333', $cte->snapshot['delivery_party']['cnpj']);
        $this->assertSame('3506003', $cte->snapshot['destination']['ibge']);
        $this->assertStringContainsString('<dest><CNPJ>00000000000191</CNPJ>', (string) $cte->payload_xml);
        $this->assertStringNotContainsString('<receb>', (string) $cte->payload_xml);
        $this->assertStringContainsString('<cMunFim>3506003</cMunFim>', (string) $cte->payload_xml);
        $this->assertCount(2, $cte->nfeDocuments);

        foreach ($cte->nfeDocuments as $nfe) {
            Storage::disk('dfe')->assertExists($nfe->xml_path);
            $this->assertSame($nfe->sha256, hash('sha256', Storage::disk('dfe')->get($nfe->xml_path)));
        }

        $origin = $this->ensureCity($cte->snapshot['origin']);
        $destination = $this->ensureCity($cte->snapshot['destination']);
        CityDistance::query()->updateOrCreate([
            'origin_ibge' => $origin->ibge_code,
            'destination_ibge' => $destination->ibge_code,
        ], ['km' => 716, 'fetched_at' => now()]);

        $payer = $this->companyForParty($cte->snapshot['dest'], $cte->snapshot['destination']);
        $delivery = $this->companyForParty($cte->snapshot['delivery_party'], $cte->snapshot['destination']);
        $vehicle = CiotVehicle::factory()->forTank()->create([
            'plate' => 'PUC8E55',
            'type' => CiotVehicle::TYPE_AUTOMOTOR,
            'rntrc' => '045963122',
            'axles' => 3,
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
            'is_active' => true,
        ]);

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
            'https://antt-hml.test/pefServices/api/EncerramentoOperacaoTransporte' => Http::response([
                'CodigoIdentificacaoOperacao' => '5200329599990001',
                'Codigo' => '110',
                'DataEncerramento' => '2026-10-10T10:00:00-03:00',
            ], 200),
        ]);

        $preparation = app(PrepareMdfeDocument::class)->handle([
            'cte_document_ids' => [$cte->id],
            'ciot_mode' => 'new',
            'new_ciot' => [
                'status' => 'draft',
                'line' => CiotLineEnum::TankAlcohol->value,
                'operation_type' => 'lotation',
                'payer_id' => $payer->id,
                'delivery_payer_id' => $delivery->id,
                'additional_payers' => [],
                'origin' => $this->ciotLocation($cte->snapshot['origin']),
                'destination' => $this->ciotLocation($cte->snapshot['destination']),
                'distance_km' => '716',
                'freight_value' => $cte->snapshot['value'],
                'cargo_weight_kg' => $cte->snapshot['cargo_weight_kg'],
                'vehicle_ids' => [$vehicle->id],
                'travel_start_at' => '2026-10-08',
                'travel_end_at' => '2026-10-10',
            ],
            'endorsements' => [['number' => 'AVERBACAO-ALC-001']],
            'driver_id' => $driver->id,
            'route_ufs' => [],
            'payment_method' => '0',
            'payment_bank_code' => '104',
            'payment_bank_agency' => '0276',
        ]);

        $ciot = $preparation->ciot->refresh();
        $mdfe = $preparation->mdfe;

        $this->assertSame(CiotStatusEnum::ISSUED, $ciot->status);
        $this->assertSame('520032959999', $ciot->ciot_number);
        $this->assertSame('3506003', $ciot->destination['ibge']);
        $this->assertNotNull($mdfe);
        $this->assertNull($mdfe->cte_emission_batch_id);
        $this->assertSame($ciot->id, $mdfe->ciot_id);
        $this->assertSame([$cte->id], $mdfe->cteDocuments->pluck('id')->all());
        $this->assertSame(2, $mdfe->reservations()->count());

        $mdfeClient = Mockery::mock(MdfeSoapClient::class);
        $mdfeClient->shouldReceive('transmit')->once()->andReturnUsing(function (string $xml): SefazResponse {
            $accessKey = $this->mdfeAccessKey($xml);

            return new SefazResponse(
                cStat: '100',
                xMotivo: 'Autorizado o uso do MDF-e',
                protocol: '935260000000021',
                rawXml: $this->authorizedMdfeResponse($accessKey),
            );
        });
        $mdfeClient->shouldReceive('transmitEvent')->once()->andReturnUsing(function (string $xml): SefazResponse {
            $accessKey = $this->mdfeEventAccessKey($xml);

            return new SefazResponse(
                cStat: '135',
                xMotivo: 'Evento registrado e vinculado ao MDF-e',
                protocol: '935260000000112',
                rawXml: $this->registeredClosureResponse($accessKey),
            );
        });
        $mdfeClient->shouldReceive('consult')->once()->andReturn(new SefazResponse(
            cStat: '132',
            xMotivo: 'Encerrado',
            rawXml: '<retConsSitMDFe xmlns="http://www.portalfiscal.inf.br/mdfe"><cStat>132</cStat><xMotivo>Encerrado</xMotivo></retConsSitMDFe>',
            closureEventProtocol: '935260000000112',
            closureEventReceivedAt: '2026-10-10T10:00:00-03:00',
        ));
        app()->instance(MdfeSoapClient::class, $mdfeClient);

        (new EmitMdfeDirectJob($mdfe->id))->handle(app(EmitMdfeDocument::class));

        $mdfe = $mdfe->refresh();
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $mdfe->status);
        $this->assertSame(FiscalDocumentStatusEnum::AUTHORIZED, $mdfe->fiscal_status);
        $this->assertSame('520032959999', $mdfe->snapshot['ciot']);
        $this->assertStringContainsString('<cMunDescarga>3506003</cMunDescarga>', (string) $mdfe->payload_xml);
        Storage::disk('dfe')->assertExists((string) $mdfe->xml_path);

        $closureCity = City::query()->where('ibge_code', $destination->ibge_code)->firstOrFail();
        $result = app(CloseTransportOperation::class)->handle($mdfe, $closureCity);

        $this->assertSame('completed', $result->status, (string) $result->message);
        $this->assertSame('132', $mdfe->refresh()->fiscal_status_code);
        $this->assertSame(FiscalDocumentStatusEnum::CLOSED, $mdfe->refresh()->fiscal_status);
        $this->assertSame(CiotStatusEnum::CLOSED, $ciot->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $cte->refresh()->status);
        $this->assertDatabaseCount('dfe_document_reservations', 0);
        Storage::disk('dfe')->assertExists((string) $mdfe->events()->firstOrFail()->xml_path);

        foreach ($cte->nfeDocuments as $nfe) {
            $this->assertSame($nfe->sha256, hash('sha256', Storage::disk('dfe')->get($nfe->xml_path)));
        }

        Http::assertSentCount(3);
    }

    public function test_canceled_vehicle_documents_rebuild_a_manifest_from_preserved_and_new_ctes_across_batches(): void
    {
        $firstBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $secondBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $thirdBatch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::COMPLETED]);
        $replacementBatchOne = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
            'execution_mode' => 'live',
        ]);
        $replacementBatchTwo = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
            'execution_mode' => 'live',
        ]);
        $preserved = $this->vehicleCte($firstBatch, '500.00', '10000.00');
        $cancelledFirst = $this->vehicleCte($secondBatch, '500.00', '11000.00');
        $cancelledSecond = $this->vehicleCte($thirdBatch, '500.00', '12000.00');
        $replacementOne = $this->queuedVehicleCte($replacementBatchOne, '400.00', '9000.00');
        $replacementTwo = $this->queuedVehicleCte($replacementBatchTwo, '400.00', '9500.00');
        $oldCiot = Ciot::factory()->issued()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'ciot_number' => '520032956638',
            'verifier_code' => '0001',
            'freight_value_cents' => 150000,
            'cargo_weight_kg' => '6000.00',
            'payer_cnpj' => '14517191000925',
            'payer_name' => 'Copart Caçapava',
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'vehicles' => [['placa' => 'PUC8E55', 'tipo' => 'automotor', 'eixos' => 3]],
        ]);
        $oldMdfe = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'workflow_origin' => 'controlled_v1',
            'execution_mode' => 'live',
            'emitter' => 'direct',
            'ciot_id' => $oldCiot->id,
            'access_key' => '35261012563112000130580010090000021123456788',
            'protocol' => '935260000000021',
        ]);
        $oldMdfe->cteDocuments()->attach([
            $preserved->id => ['position' => 1],
            $cancelledFirst->id => ['position' => 2],
            $cancelledSecond->id => ['position' => 3],
        ]);

        foreach ([$preserved, $cancelledFirst, $cancelledSecond] as $cte) {
            DfeDocumentReservation::query()->create([
                'resource_type' => 'cte',
                'resource_id' => $cte->id,
                'mdfe_document_id' => $oldMdfe->id,
            ]);
        }

        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $oldCiot->id,
            'mdfe_document_id' => $oldMdfe->id,
        ]);

        $anttClient = Mockery::mock(AnttCiotClient::class);
        $anttClient->shouldReceive('cancel')->once()->andReturn(new AnttCiotResponse(200, [
            'CodigoIdentificacaoOperacao' => $oldCiot->fullNumber(),
            'Codigo' => '110',
            'DataCancelamento' => '2026-10-07T10:00:00-03:00',
        ]));
        app()->instance(AnttCiotClient::class, $anttClient);

        $oldCiot = app(CancelCiot::class)->handle($oldCiot, 'Operação refeita após correção dos CT-es.');

        $mdfeClient = Mockery::mock(MdfeSoapClient::class);
        $mdfeClient->shouldReceive('transmitEvent')->once()->andReturnUsing(
            function (string $xml, ?callable $onTransport = null): SefazResponse {
                $accessKey = $this->mdfeAccessKeyFromEvent($xml);
                $response = $this->mdfeCancellationResponse($accessKey);
                $onTransport?->__invoke(200, 'application/xml', $response);

                return new SefazResponse(
                    cStat: '135',
                    xMotivo: 'Cancelamento homologado',
                    protocol: '935260000000099',
                    rawXml: $response,
                );
            },
        );
        $mdfeClient->shouldReceive('consult')->once()->andReturn(new SefazResponse(
            cStat: '101',
            xMotivo: 'Cancelamento homologado',
            rawXml: '<retConsSitMDFe xmlns="http://www.portalfiscal.inf.br/mdfe"><cStat>101</cStat><xMotivo>Cancelamento homologado</xMotivo></retConsSitMDFe>',
        ));
        app()->instance(MdfeSoapClient::class, $mdfeClient);

        $oldEvent = app(CancelMdfeDocument::class)->handle($oldMdfe, 'Manifesto será reconstruído com CT-es corrigidos.');

        $this->assertSame('registered', $oldEvent->status);
        $this->assertSame(FiscalDocumentStatusEnum::CANCELLED, $oldMdfe->refresh()->fiscal_status);
        $this->assertSame(CiotStatusEnum::CANCELED, $oldCiot->refresh()->status);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'mdfe_document_id' => $oldMdfe->id,
        ]);

        $cteClient = Mockery::mock(SefazSoapClient::class);
        $cteClient->shouldReceive('transmitEvent')->twice()->andReturnUsing(
            function (string $xml, ?callable $onTransport = null): SefazResponse {
                $accessKey = $this->cteAccessKeyFromEvent($xml);
                $response = $this->cteCancellationResponse($accessKey);
                $onTransport?->__invoke(200, 'application/xml', $response);

                return new SefazResponse(
                    cStat: '135',
                    xMotivo: 'Cancelamento homologado',
                    protocol: '135260000000099',
                    accessKey: $accessKey,
                    receivedAt: '2026-10-07T10:00:00-03:00',
                    rawXml: $response,
                );
            },
        );
        $cteClient->shouldReceive('consult')->twice()->andReturn(new SefazResponse(
            cStat: '101',
            xMotivo: 'Cancelamento homologado',
            rawXml: '<retConsSitCTe xmlns="http://www.portalfiscal.inf.br/cte"><cStat>101</cStat><xMotivo>Cancelamento homologado</xMotivo></retConsSitCTe>',
            hasCteCancellationEvent: true,
        ));
        app()->instance(SefazSoapClient::class, $cteClient);

        $firstEvent = app(CancelCteDocument::class)->handle($cancelledFirst, 'CT-e incorreto no manifesto anterior.');
        $secondEvent = app(CancelCteDocument::class)->handle($cancelledSecond, 'CT-e incorreto no manifesto anterior.');

        $this->assertSame('registered', $firstEvent->status);
        $this->assertSame('registered', $secondEvent->status);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $cancelledFirst->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $cancelledSecond->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $preserved->refresh()->status);

        app()->forgetInstance(SefazSoapClient::class);
        DfeSoapResponses::fakeOnline($this->cteBaseUrl);
        DfeSoapResponses::fakeTransmission($this->cteBaseUrl);

        (new EmitCteDirectJob($replacementOne->id))->handle(app(EmitCteDocument::class));
        (new EmitCteDirectJob($replacementTwo->id))->handle(app(EmitCteDocument::class));

        $replacementOne->refresh();
        $replacementTwo->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $replacementOne->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $replacementTwo->status);
        $this->assertNotNull($replacementOne->xml_path);
        $this->assertNotNull($replacementTwo->xml_path);
        $this->assertNotSame($replacementOne->cte_emission_batch_id, $replacementTwo->cte_emission_batch_id);

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
        $newCiot = Ciot::factory()->issued()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'ciot_number' => '520032956639',
            'verifier_code' => '0002',
            'freight_value_cents' => 130000,
            'cargo_weight_kg' => '6000.00',
            'payer_cnpj' => '14517191000925',
            'payer_name' => 'Copart Caçapava',
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'vehicles' => [['placa' => 'PUC8E55', 'tipo' => 'automotor', 'eixos' => 3]],
        ]);

        $rebuiltMdfe = app(QueueDirectMdfeEmission::class)->handleForDocuments(
            documents: new \Illuminate\Database\Eloquent\Collection([$preserved, $replacementOne, $replacementTwo]),
            ciot: $newCiot,
            endorsements: ['AVERBACAO-RECONSTRUCAO-001'],
            routeUfs: [],
            paymentMethod: '0',
            paymentBankCode: '033',
            paymentBankAgency: '0073',
        );

        $this->assertSame([$preserved->id, $replacementOne->id, $replacementTwo->id], $rebuiltMdfe->cteDocuments()
            ->orderBy('cte_document_mdfe_document.position')
            ->pluck('cte_documents.id')
            ->all());
        $this->assertSame($newCiot->id, $rebuiltMdfe->ciot_id);
        $this->assertNull($rebuiltMdfe->cte_emission_batch_id);
        $this->assertSame('controlled_v1', $rebuiltMdfe->workflow_origin->value);
        $this->assertDatabaseCount('dfe_document_reservations', 4);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cancelledFirst->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cancelledSecond->id,
        ]);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $replacementOne->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $replacementTwo->refresh()->status);
    }

    /** @return array{filename: string, xml: string} */
    private function fixture(string $filename, string $uploadName): array
    {
        return [
            'filename' => $uploadName,
            'xml' => (string) file_get_contents(base_path("tests/Fixtures/Dfe/Nfe/{$filename}")),
        ];
    }

    /** @param array<string, mixed> $place */
    private function ensureCity(array $place): City
    {
        return City::query()->updateOrCreate(
            ['ibge_code' => (string) $place['ibge']],
            [
                'name' => (string) $place['name'],
                'state' => (string) $place['uf'],
            ],
        );
    }

    /** @param array<string, mixed> $party
     * @param  array<string, mixed>  $place
     */
    private function companyForParty(array $party, array $place): Company
    {
        return Company::factory()->create([
            'name' => (string) ($party['xNome'] ?? $party['name']),
            'cnpj' => (string) $party['cnpj'],
            'ie' => (string) ($party['IE'] ?? 'ISENTO'),
            'street' => (string) ($party['xLgr'] ?? $party['street'] ?? 'Rua Fixture'),
            'number' => (string) ($party['nro'] ?? $party['number'] ?? '1'),
            'district' => (string) ($party['xBairro'] ?? $party['district'] ?? 'Centro'),
            'city' => (string) ($party['xMun'] ?? $party['city'] ?? $place['name']),
            'state' => (string) ($party['UF'] ?? $party['uf'] ?? $place['uf']),
            'zipcode' => (string) ($party['CEP'] ?? $party['cep'] ?? $place['cep'] ?? '14810539'),
            'ibge_code' => (string) ($party['cMun'] ?? $party['ibge'] ?? $place['ibge']),
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $place
     * @return array{cidade: string, uf: string, cep: string, ibge: string}
     */
    private function ciotLocation(array $place): array
    {
        return [
            'cidade' => (string) $place['name'],
            'uf' => (string) $place['uf'],
            'cep' => preg_replace('/\D/', '', (string) ($place['cep'] ?? $place['zipcode'] ?? '14810539')),
            'ibge' => (string) $place['ibge'],
        ];
    }

    private function mdfeAccessKey(string $xml): string
    {
        preg_match('/<infMDFe Id="MDFe(\d{44})"/', $xml, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function mdfeAccessKeyFromEvent(string $xml): string
    {
        preg_match('/<infEvento Id="ID110111(\d{44})\d{2}"/', $xml, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function cteAccessKeyFromEvent(string $xml): string
    {
        preg_match('/<infEvento Id="ID110111(\d{44})\d{3}"/', $xml, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function mdfeCancellationResponse(string $accessKey): string
    {
        return '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS2026</verAplic><cOrgao>35</cOrgao><cStat>135</cStat>'
            .'<xMotivo>Cancelamento homologado</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110111</tpEvento><xEvento>Cancelamento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-07T10:00:00-03:00</dhRegEvento><nProt>935260000000099</nProt>'
            .'</infEvento></retEventoMDFe>';
    }

    private function cteCancellationResponse(string $accessKey): string
    {
        return '<retEventoCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SP-CTe-2026-10-07-1</verAplic><cOrgao>35</cOrgao><cStat>135</cStat>'
            .'<xMotivo>Cancelamento homologado</xMotivo><chCTe>'.$accessKey.'</chCTe>'
            .'<tpEvento>110111</tpEvento><xEvento>Cancelamento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-07T10:00:00-03:00</dhRegEvento><nProt>135260000000099</nProt>'
            .'</infEvento></retEventoCTe>';
    }

    private function vehicleCte(CteEmissionBatch $batch, string $freightValue, string $fipeValue): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => null,
            'emitter' => 'direct',
            'execution_mode' => 'live',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'protocol' => '135260000000099',
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => $freightValue,
                'fipe_value' => $fipeValue,
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140'],
            ],
        ]);
    }

    private function queuedVehicleCte(CteEmissionBatch $batch, string $freightValue, string $fipeValue): CteDocument
    {
        return CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => null,
            'status' => CteDocumentStatusEnum::QUEUED,
            'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
            'emitter' => 'direct',
            'execution_mode' => 'live',
            'workflow_origin' => 'controlled_v1',
            'snapshot' => [
                'schema_version' => 2,
                'line' => CiotLineEnum::VehicleRemoval->value,
                'value' => $freightValue,
                'fipe_value' => $fipeValue,
                'vehicle_model' => 'VW GOL',
                'vehicle_plate' => 'ABC1D23',
                'payment_code' => 'T691299',
                'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
                'rem' => [
                    'cnpj' => '33164021000100',
                    'ie' => '110794302111',
                    'xNome' => 'TOKIO MARINE SEGURADORA S A VEICULOS TMS',
                    'xLgr' => 'RUA SAMPAIO VIANA',
                    'nro' => '44',
                    'xBairro' => 'PARAISO',
                    'cMun' => '3550308',
                    'xMun' => 'Sao Paulo',
                    'cep' => '04004902',
                    'uf' => 'SP',
                ],
                'dest' => [
                    'cnpj' => '14517191000178',
                    'ie' => '379121178113',
                    'xNome' => 'Copart do Brasil Organizacao de Leiloes Ltda',
                    'xLgr' => 'Estrada Muranaka',
                    'nro' => '199',
                    'xBairro' => 'Jd. Novo Horizonte',
                    'cMun' => '3508504',
                    'xMun' => 'Caçapava',
                    'cep' => '12286140',
                    'uf' => 'SP',
                ],
            ],
        ]);
    }

    private function mdfeEventAccessKey(string $xml): string
    {
        preg_match('/<infEvento Id="ID110112(\d{44})\d{2}"/', $xml, $matches);

        return (string) ($matches[1] ?? '');
    }

    private function authorizedMdfeResponse(string $accessKey): string
    {
        return '<retMDFeRecepcaoSinc xmlns="http://www.portalfiscal.inf.br/mdfe"><protMDFe versao="3.00"><infProt>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS1</verAplic><chMDFe>'.$accessKey.'</chMDFe>'
            .'<dhRecbto>2026-10-07T12:00:00-03:00</dhRecbto><nProt>935260000000021</nProt>'
            .'<cStat>100</cStat><xMotivo>Autorizado o uso do MDF-e</xMotivo>'
            .'</infProt></protMDFe></retMDFeRecepcaoSinc>';
    }

    private function registeredClosureResponse(string $accessKey): string
    {
        return '<retEventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00"><infEvento>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS1</verAplic><cOrgao>35</cOrgao><cStat>135</cStat>'
            .'<xMotivo>Evento registrado e vinculado ao MDF-e</xMotivo><chMDFe>'.$accessKey.'</chMDFe>'
            .'<tpEvento>110112</tpEvento><xEvento>Encerramento</xEvento><nSeqEvento>1</nSeqEvento>'
            .'<dhRegEvento>2026-10-10T10:00:00-03:00</dhRegEvento><nProt>935260000000112</nProt>'
            .'</infEvento></retEventoMDFe>';
    }
}
