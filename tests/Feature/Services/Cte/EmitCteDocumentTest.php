<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\IntegrationInboxItem;
use App\Services\Cte\CreateAlcoholCteDocument;
use App\Services\Cte\CteDocumentRecoveryService;
use App\Services\Cte\EmitCteDocument;
use App\Services\Cte\QueueCteDocumentEmission;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\SefazException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsCteWorld;
use Tests\Support\DfeSoapResponses;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class EmitCteDocumentTest extends TestCase
{
    use BuildsCteWorld;
    use RefreshDatabase;

    protected string $baseUrl = 'https://sefaz-hml.test/CTeWS/WS';

    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        config([
            'dfe.tp_amb' => '2',
            'dfe.base_url' => $this->baseUrl,
            'dfe.cert_path' => $certificate['path'],
            'dfe.cert_password' => $certificate['password'],
            'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
            'dfe.xsd_path' => resource_path('xsds/cte'),
        ]);

        Storage::fake('dfe');

        $this->seedCteWorld();
    }

    public function test_approving_a_batch_does_not_change_the_register_status(): void
    {
        $document = $this->liveDirectDocument();

        $this->assertSame(RegisterStatusEnum::PENDING, $document->register->refresh()->status);
    }

    public function test_authorization_completes_analysis_and_reuses_the_original_delivery(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);
        $document = $this->liveDirectDocument();
        $register = $document->register;
        $register->update(['status' => RegisterStatusEnum::ANALYSIS, 'delivery_confirmed_at' => '2026-10-01 12:00:00']);
        $item = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'cte_error', 'status' => 'alert',
        ]);

        app(EmitCteDocument::class)->attempt($document);

        $this->assertSame(RegisterStatusEnum::DELIVERED, $register->refresh()->status);
        $this->assertSame('2026-10-01 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
        $this->assertNotNull($item->refresh()->resolved_at);
    }

    public function test_authorization_without_delivery_moves_analysis_to_invoiced(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);
        $document = $this->liveDirectDocument();
        $register = $document->register;
        $register->update(['status' => RegisterStatusEnum::ANALYSIS]);

        app(EmitCteDocument::class)->attempt($document);

        $this->assertSame(RegisterStatusEnum::INVOICED, $register->refresh()->status);
        $this->assertNull($register->delivery_confirmed_at);
    }

    public function test_attempt_authorizes_the_document_and_stores_the_cte_proc(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        $authorized = app(EmitCteDocument::class)->attempt($document);

        $authorized = $authorized->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('authorized', $authorized->fiscal_status->value);
        $this->assertSame('100', $authorized->fiscal_status_code);
        $this->assertSame('135260007132708', $authorized->protocol);
        $this->assertSame(44, strlen((string) $authorized->access_key));
        $this->assertSame('900000001', $authorized->cte_number);
        $this->assertSame(RegisterStatusEnum::INVOICED, $authorized->register->refresh()->status);
        $this->assertSame('1', $authorized->series);
        $this->assertStringContainsString('<Signature', (string) $authorized->payload_xml);
        $this->assertStringContainsString('<retCTe', (string) $authorized->response_xml);

        // Cross-check: o digVal da SEFAZ ecoou o DigestValue que assinamos.
        preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', (string) $authorized->payload_xml, $matches);
        $this->assertSame($matches[1], $authorized->digest_value);

        // Arquivo fiscal de guarda no prefixo dfe/.
        $this->assertSame('cte/'.$authorized->access_key.'/cte-proc.xml', $authorized->xml_path);
        Storage::disk('dfe')->assertExists($authorized->xml_path);
        $this->assertSame(hash('sha256', (string) Storage::disk('dfe')->get($authorized->xml_path)), $authorized->sha256);

        // DACTE gerado na autorização (spec §5.1).
        $this->assertSame('cte/'.$authorized->access_key.'/dacte.pdf', $authorized->pdf_path);
        Storage::disk('dfe')->assertExists($authorized->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($authorized->pdf_path));

        // Lote com um único documento autorizado fecha como completed.
        $this->assertSame('completed', $authorized->batch->refresh()->status->value);
        $this->assertNotNull($authorized->batch->completed_at);
    }

    public function test_authorizing_an_already_invoiced_register_does_not_save_it_again(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();
        $register = $document->register;
        $register->update(['status' => RegisterStatusEnum::INVOICED]);
        $updatedAt = $register->fresh()->updated_at;
        $this->travel(5)->seconds();

        app(EmitCteDocument::class)->attempt($document);

        $this->assertSame($updatedAt?->toDateTimeString(), $register->fresh()->updated_at?->toDateTimeString());
    }

    #[DataProvider('productionDocumentScopes')]
    public function test_production_is_blocked_before_any_http_request_or_number_allocation(bool $standalone): void
    {
        Http::preventStrayRequests();
        $document = $this->liveDirectDocument();
        if ($standalone) {
            $document->update(['cte_emission_batch_id' => null]);
        }
        config(['dfe.tp_amb' => '1', 'dfe.cte.production_enabled' => false, 'dfe.numbering_start' => 3008]);
        try {
            app(EmitCteDocument::class)->attempt($document);
            $this->fail('CT-e em produção requer habilitação explícita.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('DFE_CTE_PRODUCTION_ENABLED', $exception->getMessage());
        }
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $document->fresh()->status);
        $this->assertDatabaseCount('cte_number_sequences', 0);
        Http::assertNothingSent();
    }

    public static function productionDocumentScopes(): array
    {
        return ['batch' => [false], 'standalone' => [true]];
    }

    public function test_explicit_production_switch_allows_a_mocked_authorization(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);
        $document = $this->liveDirectDocument();
        config(['dfe.tp_amb' => '1', 'dfe.cte.production_enabled' => true, 'dfe.numbering_start' => 3008, 'dfe.ca_bundle' => __FILE__]);
        $authorized = app(EmitCteDocument::class)->attempt($document)->fresh();
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('3008', $authorized->cte_number);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $authorized->payload_xml);
    }

    public function test_attempt_authorizes_a_controlled_cte_without_a_batch(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();
        $this->mock(\App\Services\Cte\CteBatchStatusUpdater::class, fn ($mock) => $mock->shouldNotReceive('handle'));
        $document->forceFill(['cte_emission_batch_id' => null])->save();

        $authorized = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('authorized', $authorized->fiscal_status->value);
        $this->assertNull($authorized->cte_emission_batch_id);
        $this->assertNull($authorized->batch);
        $this->assertNotNull($authorized->xml_path);
    }

    public function test_attempt_records_a_confirmed_rejection_without_a_batch(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, cStat: '225', xMotivo: 'Rejeicao: Duplicidade de CT-e');

        $document = $this->liveDirectDocument();
        $this->mock(\App\Services\Cte\CteBatchStatusUpdater::class, fn ($mock) => $mock->shouldNotReceive('handle'));
        $document->forceFill(['cte_emission_batch_id' => null])->save();

        $rejected = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::REJECTED, $rejected->status);
        $this->assertSame('not_authorized', $rejected->fiscal_status->value);
        $this->assertNull($rejected->cte_emission_batch_id);
        $this->assertNotSame(RegisterStatusEnum::INVOICED, $rejected->register->refresh()->status);
    }

    public function test_a_digval_mismatch_marks_reconciliation_required_and_skips_the_store(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, forcedDigVal: base64_encode(str_repeat('x', 20)));

        $document = $this->liveDirectDocument();
        $register = $document->register;
        $initialRegisterStatus = $register->status;

        $document = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::RECONCILIATION_REQUIRED, $document->status);
        $this->assertNull($document->fiscal_status);
        $this->assertSame('digval_mismatch', $document->error_code);
        $this->assertSame('100', $document->fiscal_status_code);
        $this->assertNotNull($document->payload_xml);
        $this->assertNull($document->xml_path);
        $this->assertSame($initialRegisterStatus, $register->refresh()->status);
    }

    public function test_a_content_rejection_marks_the_document_as_rejected(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, cStat: '225', xMotivo: 'Rejeicao: Duplicidade de CT-e');

        $document = $this->liveDirectDocument();
        $document->register->update(['status' => RegisterStatusEnum::ANALYSIS]);

        $document = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::REJECTED, $document->status);
        $this->assertSame(RegisterStatusEnum::ANALYSIS, $document->register->refresh()->status);
        $this->assertSame('not_authorized', $document->fiscal_status->value);
        $this->assertSame('225', $document->fiscal_status_code);
        $this->assertStringContainsString('Duplicidade', (string) $document->fiscal_status_message);
        $this->assertNull($document->xml_path);
    }

    public function test_retrying_a_batch_cfop_rejection_rebuilds_the_xml_with_the_correct_cfop(): void
    {
        Http::preventStrayRequests();
        DfeSoapResponses::fakeTransmission($this->baseUrl);
        config(['dfe.cte.cfop' => '5353']);
        $document = $this->liveDirectDocument();
        $snapshot = $document->snapshot;
        $snapshot['origin'] = ['ibge' => '4106902', 'name' => 'Curitiba', 'uf' => 'PR'];
        $document->forceFill([
            'snapshot' => $snapshot,
            'status' => CteDocumentStatusEnum::REJECTED,
            'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
            'cte_number' => '3075',
            'access_key' => str_repeat('3', 44),
            'payload_xml' => '<CTe><ide><CFOP>5353</CFOP></ide></CTe>',
            'fiscal_status_code' => '519',
            'fiscal_status_message' => 'Rejeição: CFOP inválido para operação.',
        ])->save();
        $document->batch->forceFill([
            'status' => CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            'completed_at' => now(),
        ])->save();

        $queued = app(CteDocumentRecoveryService::class)->retry($document);
        $this->assertNull($queued->payload_xml);
        (new EmitCteDirectJob($queued->id))->handle(app(EmitCteDocument::class));

        $document->refresh();
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->status);
        $this->assertSame($snapshot, $document->snapshot);
        $this->assertStringContainsString('<CFOP>6932</CFOP>', (string) $document->payload_xml);
        $this->assertNotSame('3075', $document->cte_number);
        Http::assertSent(fn ($request): bool => str_contains(
            DfeSoapResponses::transmittedXml($request->body()),
            '<CFOP>6932</CFOP>',
        ));
    }

    public function test_a_processing_batch_without_recorded_approval_cannot_be_emitted(): void
    {
        Http::preventStrayRequests();
        $document = $this->liveDirectDocument();
        $document->batch->forceFill([
            'status' => CteEmissionBatchStatusEnum::PROCESSING,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        (new EmitCteDirectJob($document->id))->handle(app(EmitCteDocument::class));

        $document->refresh();
        $this->assertSame(CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, $document->status);
        $this->assertSame('Somente lotes aprovados podem ser emitidos.', $document->error_message);
        $this->assertNull($document->payload_xml);
        $this->assertDatabaseCount('cte_number_sequences', 0);
        Http::assertNothingSent();
    }

    public function test_a_retryable_failure_leaves_the_document_authorizing_for_the_job(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        Http::fake([
            $this->baseUrl.'/CTeRecepcaoSincV4.asmx' => Http::response('boom', 503),
        ]);

        $document = $this->liveDirectDocument();

        $this->expectException(SefazException::class);

        try {
            app(EmitCteDocument::class)->attempt($document);
        } finally {
            $this->assertSame(CteDocumentStatusEnum::AUTHORIZING, $document->refresh()->status);
        }
    }

    public function test_the_job_marks_a_rule_violation_as_failed_before_authorization(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();
        $this->mock(\App\Services\Cte\CteBatchStatusUpdater::class, fn ($mock) => $mock->shouldNotReceive('handle'));

        // Seguradora sem cadastro: violação de regra/dados (terminal).
        $document->forceFill([
            'cte_emission_batch_id' => null,
            'snapshot' => array_merge($document->snapshot, ['schema_version' => 1, 'insurance' => 'PIER SEGURADORA']),
        ])->save();

        (new EmitCteDirectJob($document->id))->handle(app(EmitCteDocument::class));

        $document = $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, $document->status);
        $this->assertNull($document->cte_emission_batch_id);
        $this->assertStringContainsString('PIER SEGURADORA', (string) $document->error_message);
    }

    public function test_the_direct_job_cannot_emit_alcohol_without_archived_nfe_originals(): void
    {
        Http::fake();

        $xml = (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml'));
        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [['filename' => 'entrada.xml', 'xml' => $xml]],
            freightValue: '450.00',
            deliveryDate: now()->addDay()->toDateString(),
        );
        $document->nfeDocuments()->delete();
        $document->forceFill(['status' => CteDocumentStatusEnum::QUEUED])->save();

        (new EmitCteDirectJob($document->id))->handle(app(EmitCteDocument::class));

        $this->assertSame(CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION, $document->refresh()->status);
        $this->assertStringContainsString('vínculos arquivados das NF-es', (string) $document->error_message);
        Http::assertNothingSent();
    }

    public function test_queues_and_authorizes_a_multi_nfe_alcohol_draft_without_receiver_and_with_dacte(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = app(CreateAlcoholCteDocument::class)->handle(
            files: [
                [
                    'filename' => 'primeira.xml',
                    'xml' => (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml')),
                ],
                [
                    'filename' => 'segunda.xml',
                    'xml' => (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-002.xml')),
                ],
            ],
            freightValue: '450.00',
            deliveryDate: now()->addDay()->toDateString(),
        );
        $frozenSnapshot = $document->snapshot;

        $queued = app(QueueCteDocumentEmission::class)->handle($document);
        (new EmitCteDirectJob($queued->id))->handle(app(EmitCteDocument::class));

        $authorized = $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('authorized', $authorized->fiscal_status->value);
        $this->assertSame($frozenSnapshot, $authorized->snapshot);
        $this->assertSame(2, substr_count((string) $authorized->payload_xml, '<infNFe>'));
        $this->assertStringNotContainsString('<receb>', (string) $authorized->payload_xml);
        $this->assertStringContainsString('<cMunFim>3506003</cMunFim>', (string) $authorized->payload_xml);
        $this->assertStringContainsString('<dest><CNPJ>00000000000191</CNPJ>', (string) $authorized->payload_xml);
        $this->assertSame(2, $authorized->nfeDocuments()->count());
        Storage::disk('dfe')->assertExists((string) $authorized->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get((string) $authorized->pdf_path));
    }

    public function test_job_exhaustion_after_transmission_requires_reconciliation(): void
    {
        $document = $this->liveDirectDocument();
        $key = '35261012563112000130570010000027011338262343';
        $document->forceFill([
            'status' => CteDocumentStatusEnum::AUTHORIZING,
            'access_key' => $key,
            'payload_xml' => '<CTe/>',
        ])->save();

        (new EmitCteDirectJob($document->id))->failed(new RuntimeException('resultado de transmissão desconhecido'));

        $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::RECONCILIATION_REQUIRED, $document->status);
        $this->assertSame($key, $document->access_key);
        $this->assertSame('<CTe/>', $document->payload_xml);
        $this->assertSame('transmission', $document->error_stage);
        $this->assertSame('transmission_outcome_unknown', $document->error_code);
    }

    public function test_a_terminal_document_is_not_retransmitted_by_the_job(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();
        $register = $document->register;

        $job = new EmitCteDirectJob($document->id);
        $job->handle(app(EmitCteDocument::class));

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);

        // Segunda execução do job (retry/broker duplicado): nada transmite.
        Http::fake([
            $this->baseUrl.'/CTeRecepcaoSincV4.asmx' => Http::response('never', 200),
        ]);

        $job->handle(app(EmitCteDocument::class));

        Http::assertNothingSent();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->refresh()->status);
        $register->refresh()->update(['status' => RegisterStatusEnum::AVAILABLE]);

        $job->handle(app(EmitCteDocument::class));

        $this->assertSame(RegisterStatusEnum::AVAILABLE, $register->refresh()->status);
    }

    /**
     * Regra do orquestrador: o nCT queima SEMPRE (a alocação não participa do
     * rollback do registro). E com o guard de retransmissão, o retry RECUPERA
     * o número queimado quando a consulta prova que a SEFAZ autorizou: S3
     * falhou → retry consulta → registra sem retransmitir e sem queimar outro.
     */
    public function test_a_failed_persistence_recovers_the_burned_number_from_the_consult_on_retry(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        $this->mock(DfeDocumentStore::class, fn ($mock) => $mock
            ->shouldReceive('storeCteProc')
            ->andThrow(new RuntimeException('S3 indisponível')));

        try {
            app(EmitCteDocument::class)->attempt($document);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('S3 indisponível', $exception->getMessage());
        }

        // O rollback do registro devolveu o documento para authorizing...
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZING, $document->refresh()->status);
        $this->assertNull($document->xml_path);
        $this->assertSame('authorized', $document->fiscal_status->value);
        $this->assertSame('135260007132708', $document->protocol);
        $this->assertNotNull($document->response_xml);
        $this->assertSame('artifact', $document->error_stage);
        $this->assertSame('archive_pending', $document->error_code);
        $register = $document->register->refresh();
        $this->assertSame(RegisterStatusEnum::INVOICED, $register->status);
        $register->update(['status' => RegisterStatusEnum::AVAILABLE]);

        // ...mas o número QUEIMOU: a sequência avançou.
        $this->assertDatabaseHas('cte_number_sequences', [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000002,
        ]);

        // Retry com o store real: consulta a chave queimada → SEFAZ autorizou
        // → registra o protocolo sem retransmitir e sem queimar outro número.
        $this->app->forgetInstance(DfeDocumentStore::class);

        preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', (string) $document->payload_xml, $digestMatches);

        Http::fake([
            $this->baseUrl.'/CTeConsultaV4.asmx' => fn ($request) => Http::response(
                DfeSoapResponses::retConsSitEnvelope(
                    DfeSoapResponses::accessKeyFromConsult($request->body()),
                    '100',
                    '135260007139999',
                    $digestMatches[1],
                ),
                200,
            ),
        ]);

        $authorized = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('900000001', $authorized->cte_number, 'O número queimado é recuperado pela consulta.');
        $this->assertSame('135260007139999', $authorized->protocol);
        $this->assertNotNull($authorized->xml_path, 'O cteProc é gravado agora que o S3 voltou.');
        $this->assertSame(RegisterStatusEnum::AVAILABLE, $authorized->register->refresh()->status);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('cte_number_sequences', [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000002,
        ]);
    }

    /**
     * Crash pós-transmissão simulado: a primeira tentativa persiste os
     * artifacts e falha (503); na vida real a SEFAZ pode ter autorizado. O
     * retry consulta a chave queimada e REGISTRA o protocolo sem retransmitir.
     */
    public function test_a_retry_consults_the_burned_key_and_records_without_retransmitting(): void
    {
        $document = $this->liveDirectDocument();

        $transmissions = 0;

        Http::fake(function ($request) use ($document, &$transmissions) {
            if (str_contains($request->url(), 'CTeConsultaV4')) {
                preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', (string) $document->payload_xml, $digestMatches);

                return Http::response(
                    DfeSoapResponses::retConsSitEnvelope(
                        DfeSoapResponses::accessKeyFromConsult($request->body()),
                        '100',
                        '135260007139999',
                        $digestMatches[1],
                    ),
                    200,
                );
            }

            $transmissions++;

            return $transmissions === 1
                ? Http::response('boom', 503)
                : Http::response('NUNCA DEVE RETRANSMITIR', 500);
        });

        try {
            app(EmitCteDocument::class)->attempt($document);
            $this->fail('Expected SefazException.');
        } catch (SefazException) {
        }

        $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZING, $document->status);
        $this->assertSame('900000001', $document->cte_number);
        $this->assertNotNull($document->payload_xml);

        $authorized = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('900000001', $authorized->cte_number, 'O número original é recuperado, não queimado de novo.');
        $this->assertSame('135260007139999', $authorized->protocol);
        $this->assertNotNull($authorized->xml_path);
        $this->assertSame(1, $transmissions, 'O retry NÃO retransmite: registra a partir da consulta.');
    }

    /**
     * Chave queimada que a consulta prova NÃO autorizada: nova construção
     * queima o próximo número (regra "o número queima sempre").
     */
    public function test_a_retry_with_an_unauthorized_burned_key_builds_a_new_document(): void
    {
        $document = $this->liveDirectDocument();

        $transmissions = 0;

        Http::fake(function ($request) use (&$transmissions) {
            if (str_contains($request->url(), 'CTeConsultaV4')) {
                return Http::response(
                    DfeSoapResponses::retConsSitEnvelope(
                        DfeSoapResponses::accessKeyFromConsult($request->body()),
                        '218',
                        '',
                        base64_encode('x'),
                        'Rejeicao: CT-e nao consta na base de dados da SEFAZ',
                    ),
                    200,
                );
            }

            $transmissions++;

            return $transmissions === 1
                ? Http::response('boom', 503)
                : Http::response(
                    DfeSoapResponses::retCteEnvelope(
                        DfeSoapResponses::transmittedXml($request->body()),
                        null,
                        '100',
                        'Autorizado o uso do CT-e',
                        '135260007132708',
                    ),
                    200,
                );
        });

        try {
            app(EmitCteDocument::class)->attempt($document);
        } catch (SefazException) {
        }

        $authorized = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertSame('900000002', $authorized->cte_number);

        $consulted = Http::recorded(fn ($request): bool => str_contains($request->url(), 'CTeConsultaV4'));
        $this->assertCount(1, $consulted);

        $this->assertNotSame($authorized->access_key, DfeSoapResponses::accessKeyFromConsult($consulted->first()[0]->body()));
    }

    /**
     * A autorização é sagrada: falha na geração do DACTE não derruba o
     * registro — pdf_path fica null e a listagem regenera sob demanda.
     */
    public function test_a_dacte_generation_failure_does_not_lose_the_authorization(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $document = $this->liveDirectDocument();

        $this->mock(\App\Services\Dfe\GenerateDactePdf::class, fn ($mock) => $mock
            ->shouldReceive('generateForProc')
            ->andThrow(new RuntimeException('fonte do PDF explodiu')));

        $authorized = app(EmitCteDocument::class)->attempt($document)->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $authorized->status);
        $this->assertNotNull($authorized->xml_path);
        $this->assertNull($authorized->pdf_path);
    }
}
