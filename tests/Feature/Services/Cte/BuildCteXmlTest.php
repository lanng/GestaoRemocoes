<?php

namespace Tests\Feature\Services\Cte;

use App\Services\Cte\BuildCteXml;
use App\Services\Dfe\AccessKey;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class BuildCteXmlTest extends TestCase
{
    use RefreshDatabase;

    protected BuildCteXml $builder;

    protected XmlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.cte.serie' => '1',
            'dfe.numbering_start' => null,
        ]);

        // Data fixa para o dhEmi ser determinístico nos testes.
        Carbon::setTestNow('2026-10-01 12:00:00');

        // O XSD oficial do CT-e exige o ds:Signature — a validação local roda
        // sobre o XML JÁ ASSINADO (assinar → validar → transmitir; ver relatório).
        $certificate = DfeTestCertificate::create();
        config(['dfe.cert_path' => $certificate['path'], 'dfe.cert_password' => $certificate['password']]);

        $this->signer = new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password']));
        $this->builder = app(BuildCteXml::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Constrói, assina e valida o XSD — devolve o BuiltCte para as asserções.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function signed(array $snapshot): \App\Services\Cte\BuiltCte
    {
        $built = $this->builder->handle($snapshot);

        $signedXml = $this->signer->sign($built->xml, 'infCte', 'http://www.portalfiscal.inf.br/cte', 'CTe'.$built->accessKey);

        (app(XsdValidator::class))->validateCte($signedXml);

        return $built;
    }

    public function test_builds_the_vehicle_line_xml_from_a_real_snapshot_and_validates_the_xsd(): void
    {
        $built = $this->signed($this->vehicleSnapshot());

        $this->assertSame(900000001, $built->number);
        $this->assertSame(1, $built->serie);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $built->cct);
        $this->assertSame(44, strlen($built->accessKey));
        $this->assertSame(AccessKey::mod11(substr($built->accessKey, 0, 43)), substr($built->accessKey, -1));

        $xml = $built->xml;

        // Cabeçalho/ide: numeração de teste, chave e ambiente derivados de tp_amb.
        $this->assertStringContainsString('<infCte Id="CTe'.$built->accessKey.'" versao="4.00">', $xml);
        $this->assertStringContainsString('<nCT>900000001</nCT>', $xml);
        $this->assertStringContainsString('<serie>1</serie>', $xml);
        $this->assertStringContainsString('<cDV>'.substr($built->accessKey, -1).'</cDV>', $xml);
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<dhEmi>2026-10-01T09:00:00-03:00</dhEmi>', $xml);
        $this->assertStringContainsString('<verProc>Sistema interno 1.0</verProc>', $xml);
        $this->assertStringContainsString('<cMunIni>3504008</cMunIni><xMunIni>Assis</xMunIni><UFIni>SP</UFIni>', $xml);
        $this->assertStringContainsString('<cMunFim>3523107</cMunFim><xMunFim>Itaquaquecetuba</xMunFim><UFFim>SP</UFFim>', $xml);
        $this->assertStringContainsString('<indIEToma>9</indIEToma>', $xml);

        // Emissor fixo da config (spec §2.5).
        $this->assertStringContainsString('<CNPJ>12563112000130</CNPJ><IE>150046654114</IE>', $xml);
        $this->assertStringContainsString('<xNome>FERNANDES &amp; FERNANDES LTDA</xNome>', $xml);

        // Regras de homologação (§2.4): letreiro, sem IE e sem xFant nas partes
        // (o emit mantém o xFant, como no gabarito homolog autorizado).
        $this->assertSame(2, substr_count($xml, '<CNPJ>00000000000191</CNPJ>'));
        $this->assertSame(2, substr_count($xml, '<xNome>CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL</xNome>'));
        $this->assertStringContainsString('SEM VALOR FISCAL</xNome><enderReme>', $xml);
        $this->assertStringContainsString('SEM VALOR FISCAL</xNome><enderDest>', $xml);
        $this->assertStringNotContainsString('<IE>110794302111</IE>', $xml);
        $this->assertStringContainsString('<IE>150046654114</IE>', $xml);

        // Linha veículos: vPrest com Comp FRETE, infCarga FIPE/carta, infOutros.
        $this->assertStringContainsString('<vPrest><vTPrest>898.45</vTPrest><vRec>898.45</vRec><Comp><xNome>FRETE</xNome><vComp>898.45</vComp></Comp></vPrest>', $xml);
        $this->assertStringContainsString('<vCarga>139329.00</vCarga>', $xml);
        $this->assertStringContainsString('<proPred>AMAROK-PLACA:BDA4E98</proPred>', $xml);
        $this->assertStringContainsString('<xOutCat>T718089</xOutCat>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>2000.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infDoc><infOutros><tpDoc>99</tpDoc><descOutros>CARTA DE REMOCAO</descOutros><nDoc>0</nDoc><dEmi>2026-10-01</dEmi></infOutros></infDoc>', $xml);
        $this->assertStringContainsString('<infModal versaoModal="4.00"><rodo><RNTRC>45963122</RNTRC></rodo></infModal>', $xml);

        // QR de homologação, sem parâmetro sign (spec §2.4).
        $this->assertStringContainsString('<infCTeSupl xmlns="http://www.portalfiscal.inf.br/cte"><qrCodCTe><![CDATA[https://homologacao.nfe.fazenda.sp.gov.br/CTeConsulta/qrCode?chCTe='.$built->accessKey.'&tpAmb=2]]></qrCodCTe></infCTeSupl>', $xml);
    }

    public function test_builds_the_tank_line_xml_with_the_inf_nfe_branch(): void
    {
        $built = $this->signed($this->tankSnapshot());

        $xml = $built->xml;

        $this->assertStringContainsString('<CFOP>5353</CFOP>', $xml);
        $this->assertStringContainsString('<vPrest><vTPrest>3725.81</vTPrest><vRec>3725.81</vRec></vPrest>', $xml);
        $this->assertStringNotContainsString('<Comp>', $xml);
        $this->assertStringContainsString('<proPred>ETANOL HIDRATADO COMBUSTIVEL</proPred>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>04</cUnid><tpMed>LITROS</tpMed><qCarga>21879.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infDoc><infNFe><chave>35260945483450002164550010004300881365784943</chave><dPrev>2026-10-02</dPrev></infNFe></infDoc>', $xml);
        $this->assertStringNotContainsString('<xOutCat>', $xml);
    }

    /** @param array{ibge: string, name: string, uf: string} $origin */
    #[DataProvider('batchCfopRoutes')]
    public function test_batch_cfop_follows_the_physical_route_and_validates_the_xsd(
        string $environment,
        array $origin,
        string $configuredCfop,
        string $expectedCfop,
    ): void {
        config([
            'dfe.tp_amb' => $environment,
            'dfe.emit.uf' => 'SP',
            'dfe.cte.cfop' => $configuredCfop,
            'dfe.numbering_start' => $environment === '1' ? 3075 : null,
        ]);

        $snapshot = $this->vehicleSnapshot();
        $snapshot['origin'] = $origin;

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<CFOP>'.$expectedCfop.'</CFOP>', $xml);
        $this->assertStringContainsString('<UFIni>'.$origin['uf'].'</UFIni>', $xml);
        $this->assertStringContainsString('<UFFim>SP</UFFim>', $xml);
    }

    public static function batchCfopRoutes(): array
    {
        $sp = ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'];
        $pr = ['ibge' => '4106902', 'name' => 'Curitiba', 'uf' => 'PR'];
        $mg = ['ibge' => '3106200', 'name' => 'Belo Horizonte', 'uf' => 'MG'];
        $cases = [];

        foreach (['1', '2'] as $environment) {
            $cases[$environment.' PR to SP'] = [$environment, $pr, '5353', '6932'];
            $cases[$environment.' MG to SP'] = [$environment, $mg, '5353', '6932'];
            $cases[$environment.' within SP'] = [$environment, $sp, '5353', '5353'];
            $cases[$environment.' configured SP CFOP'] = [$environment, $sp, '5352', '5352'];
        }

        return $cases;
    }

    public function test_builds_generic_cargo_xml_with_manual_freight_and_multiple_measurements(): void
    {
        $built = $this->signed($this->generalCargoSnapshot());
        $xml = $built->xml;

        $this->assertStringContainsString('<CFOP>5353</CFOP>', $xml);
        $this->assertStringContainsString('<natOp>PREST SERV TRANSPORTE</natOp>', $xml);
        $this->assertStringContainsString('<indIEToma>9</indIEToma><toma3><toma>3</toma></toma3>', $xml);
        $this->assertStringContainsString('<vTPrest>5000.00</vTPrest><vRec>4800.00</vRec>', $xml);
        $this->assertStringContainsString('<Comp><xNome>FRETE</xNome><vComp>4500.00</vComp></Comp>', $xml);
        $this->assertStringContainsString('<Comp><xNome>PEDAGIO</xNome><vComp>500.00</vComp></Comp>', $xml);
        $this->assertStringContainsString('<vCarga>25000.00</vCarga><proPred>MAQUINAS AGRICOLAS</proPred><xOutCat>PECAS PESADAS</xOutCat>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>01</cUnid><tpMed>PESO BRUTO</tpMed><qCarga>12000.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infQ><cUnid>03</cUnid><tpMed>UNIDADES</tpMed><qCarga>4.0000</qCarga></infQ>', $xml);
        $this->assertStringContainsString('<infDoc><infNFe><chave>35261011111111111111550010000000011000000011</chave></infNFe></infDoc>', $xml);
        $this->assertStringNotContainsString('CARTA DE REMOCAO', $xml);
        $this->assertStringNotContainsString('<dPrev>', $xml);
    }

    public function test_builds_and_xsd_validates_general_cargo_with_estimated_weight_only(): void
    {
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['cargo']['measurements'] = [
            ['unit' => '01', 'measure' => 'PESO ESTIMADO', 'quantity' => '1500.0000'],
        ];

        $built = $this->signed($snapshot);

        $this->assertStringContainsString(
            '<infQ><cUnid>01</cUnid><tpMed>PESO ESTIMADO</tpMed><qCarga>1500.0000</qCarga></infQ>',
            $built->xml,
        );
        $this->assertStringNotContainsString('<tpMed>PESO BRUTO</tpMed>', $built->xml);
    }

    public function test_builds_and_xsd_validates_general_cargo_with_units_only(): void
    {
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['cargo']['measurements'] = [
            ['unit' => '03', 'measure' => 'VEICULOS', 'quantity' => '3.0000'],
        ];

        $built = $this->signed($snapshot);

        $this->assertStringContainsString(
            '<infQ><cUnid>03</cUnid><tpMed>VEICULOS</tpMed><qCarga>3.0000</qCarga></infQ>',
            $built->xml,
        );
        $this->assertStringNotContainsString('<tpMed>PESO BRUTO</tpMed>', $built->xml);
    }

    public function test_builds_a_carta_origin_document_without_fabricated_number_or_date_and_renders_it_in_dacte(): void
    {
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['schema_version'] = 5;
        $snapshot['origin_document_mode'] = 'other';
        $snapshot['nfe_documents'] = [];
        $snapshot['other_document'] = [
            'type' => 'carta_remocao',
            'tp_doc' => '99',
            'description' => 'CARTA DE REMOCAO',
            'number' => null,
            'issued_on' => null,
            'attachment' => null,
        ];

        $built = $this->signed($snapshot);
        $xml = $built->xml;

        $this->assertStringContainsString(
            '<infDoc><infOutros><tpDoc>99</tpDoc><descOutros>CARTA DE REMOCAO</descOutros></infOutros></infDoc>',
            $xml,
        );
        $this->assertStringNotContainsString('<nDoc>', $xml);
        $this->assertStringNotContainsString('<dEmi>', $xml);
        $this->assertStringNotContainsString('<exped>', $xml);
        $this->assertStringNotContainsString('<receb>', $xml);

        $dacte = \App\Services\Dfe\DacteData::fromProc($xml);
        $html = view('dfe.dacte', ['dacte' => $dacte, 'qrImage' => null])->render();

        $this->assertSame('CARTA DE REMOCAO', $dacte->document['descOutros']);
        $this->assertNull($dacte->document['nDoc']);
        $this->assertNull($dacte->document['dEmi']);
        $this->assertStringContainsString('CARTA DE REMOCAO', $html);
        $this->assertStringNotContainsString('Emissão:', $html);

        Storage::fake('dfe');
        $pdfPath = app(\App\Services\Dfe\GenerateDactePdf::class)->generateForProc($built->accessKey, $xml);

        $this->assertStringStartsWith('%PDF', Storage::disk('dfe')->get($pdfPath));
    }

    public function test_emits_real_carta_number_and_issue_date_when_they_are_provided(): void
    {
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['schema_version'] = 5;
        $snapshot['origin_document_mode'] = 'other';
        $snapshot['nfe_documents'] = [];
        $snapshot['other_document'] = [
            'type' => 'carta_remocao',
            'tp_doc' => '99',
            'description' => 'CARTA DE REMOCAO',
            'number' => 'REM-123',
            'issued_on' => '2026-10-15',
            'attachment' => null,
        ];

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString(
            '<infOutros><tpDoc>99</tpDoc><descOutros>CARTA DE REMOCAO</descOutros><nDoc>REM-123</nDoc><dEmi>2026-10-15</dEmi></infOutros>',
            $xml,
        );
    }

    public function test_builds_supported_tomador_codes_from_the_selected_party(): void
    {
        foreach (['0', '1', '2', '3', '4'] as $code) {
            $snapshot = $this->generalCargoSnapshot();
            $snapshot['taker_code'] = $code;

            if ($code === '1') {
                $snapshot['exped'] = $this->genericParty('Expedidor', '123456789');
                $snapshot['ind_ietoma'] = '1';
            } elseif ($code === '2') {
                $snapshot['receb'] = $this->genericParty('Recebedor', '');
                $snapshot['ind_ietoma'] = '9';
            } elseif ($code === '0') {
                $snapshot['ind_ietoma'] = '1';
            } elseif ($code === '4') {
                $snapshot['taker'] = $this->genericParty('Tomador outro', '');
                $snapshot['ind_ietoma'] = '9';
            }

            $xml = $this->signed($snapshot)->xml;

            if ($code === '4') {
                $this->assertStringContainsString('<toma4><toma>4</toma><CNPJ>00000000000191</CNPJ>', $xml);
            } else {
                $this->assertStringContainsString('<toma3><toma>'.$code.'</toma></toma3>', $xml);
            }

            $this->assertStringContainsString('<indIEToma>9</indIEToma>', $xml);
        }
    }

    public function test_homolog_general_cargo_uses_non_contributor_indicator_for_synthetic_sender(): void
    {
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['taker_code'] = '0';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        $this->assertStringContainsString('<indIEToma>9</indIEToma><toma3><toma>0</toma></toma3>', $xml);
        $this->assertStringContainsString(
            '<rem><CNPJ>00000000000191</CNPJ><xNome>CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL</xNome>',
            $xml,
        );
        $this->assertStringNotContainsString('<rem><CNPJ>00000000000191</CNPJ><IE>', $xml);
    }

    public function test_production_general_cargo_preserves_real_sender_indicator_and_inscription(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['taker_code'] = '0';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<indIEToma>1</indIEToma><toma3><toma>0</toma></toma3>', $xml);
        $this->assertStringContainsString('<rem><CNPJ>11111111111111</CNPJ><IE>123456789</IE>', $xml);
    }

    public function test_generic_cargo_uses_the_explicit_non_contributor_status_even_when_taker_has_ie(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['ind_ietoma'] = '9';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<indIEToma>9</indIEToma>', $xml);
        $this->assertStringContainsString('<dest><CNPJ>11111111111111</CNPJ><IE>987654321</IE>', $xml);
    }

    public function test_generic_cargo_emits_isento_ie_for_an_explicit_exempt_tomador(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $snapshot = $this->generalCargoSnapshot();
        $snapshot['taker_code'] = '4';
        $snapshot['ind_ietoma'] = '2';
        $snapshot['taker'] = $this->genericParty('Tomador isento', '');
        $snapshot['taker']['cMun'] = '4106902';
        $snapshot['taker']['xMun'] = 'Curitiba';
        $snapshot['taker']['CEP'] = '80010000';
        $snapshot['taker']['UF'] = 'PR';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<indIEToma>2</indIEToma><toma4><toma>4</toma>', $xml);
        $this->assertStringContainsString('<IE>ISENTO</IE><xNome>Tomador isento</xNome>', $xml);
    }

    public function test_rejects_an_unknown_snapshot_line_instead_of_treating_it_as_vehicle_removal(): void
    {
        $snapshot = $this->vehicleSnapshot();
        $snapshot['line'] = 'unknown';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Modalidade');

        $this->builder->handle($snapshot);
    }

    public function test_builds_multiple_nfe_references_without_a_receiver_group(): void
    {
        $snapshot = $this->tankSnapshot();
        $snapshot['schema_version'] = 3;
        unset($snapshot['nfe_key']);
        $snapshot['nfe_documents'] = [
            ['access_key' => '35261011111111111111550010000000011000000011'],
            ['access_key' => '35261011111111111111550010000000021000000027'],
        ];
        $snapshot['dprev'] = '2026-10-03';
        $snapshot['destination'] = ['ibge' => '3506003', 'name' => 'Campinas', 'uf' => 'SP'];
        $snapshot['delivery_party'] = [
            'cpf' => '12345678901',
            'ie' => '',
            'xNome' => 'Recebedor físico da carga',
            'xLgr' => 'Rua da Entrega',
            'nro' => '300',
            'xCpl' => 'Portão 2',
            'xBairro' => 'Industrial',
            'cMun' => '3506003',
            'xMun' => 'Campinas',
            'cep' => '13000000',
            'uf' => 'SP',
        ];
        $snapshot['delivery_missing_fields'] = [];

        $built = $this->signed($snapshot);
        $xml = $built->xml;

        $this->assertSame(2, substr_count($xml, '<infNFe>'));
        $this->assertStringContainsString('<chave>35261011111111111111550010000000011000000011</chave><dPrev>2026-10-03</dPrev>', $xml);
        $this->assertStringContainsString('<chave>35261011111111111111550010000000021000000027</chave><dPrev>2026-10-03</dPrev>', $xml);
        $this->assertStringContainsString('<cMunFim>3506003</cMunFim><xMunFim>Campinas</xMunFim><UFFim>SP</UFFim>', $xml);
        $this->assertStringNotContainsString('<receb>', $xml);
        $this->assertStringNotContainsString('<enderReceb>', $xml);
        $this->assertStringContainsString('<dest><CNPJ>00000000000191</CNPJ>', $xml);
    }

    public function test_production_derives_real_parties_and_qr_from_tp_amb(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => 2964]);

        $snapshot = $this->vehicleSnapshot();
        $snapshot['delivery_party'] = [
            'cnpj' => '48123456000178',
            'ie' => '123456789',
            'xNome' => 'Recebedor físico diferente do destinatário',
            'xLgr' => 'Rua da Entrega',
            'nro' => '300',
            'xBairro' => 'Industrial',
            'cMun' => '3523107',
            'xMun' => 'Itaquaquecetuba',
            'cep' => '08597000',
            'uf' => 'SP',
        ];

        $built = $this->signed($snapshot);

        $xml = $built->xml;

        $this->assertStringContainsString('<nCT>2964</nCT>', $xml);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<indIEToma>1</indIEToma>', $xml);

        // Partes reais do snapshot, com IE e xFant no rem (gabarito 2962).
        $this->assertStringContainsString('<rem><CNPJ>33164021000100</CNPJ><IE>110794302111</IE><xNome>TOKIO MARINE SEGURADORA S.A</xNome><xFant>TOKIO MARINE SEGURADORA S.A</xFant>', $xml);
        $this->assertStringContainsString('<dest><CNPJ>14517191000178</CNPJ><IE>379121178113</IE><xNome>Copart do Brasil Organizacao de Leiloes Ltda</xNome>', $xml);
        $this->assertStringNotContainsString('SEM VALOR FISCAL', $xml);
        $this->assertStringNotContainsString('<receb>', $xml);

        $this->assertStringContainsString('https://nfe.fazenda.sp.gov.br/CTeConsulta/qrCode?chCTe='.$built->accessKey.'&tpAmb=1', $xml);
    }

    public function test_rejects_an_incomplete_snapshot_listening_every_missing_field(): void
    {
        try {
            $this->builder->handle(['line' => 'vehicle_removal', 'schema_version' => 2]);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('value', $exception->getMessage());
            $this->assertStringContainsString('origin.ibge', $exception->getMessage());
            $this->assertStringContainsString('vehicle_model', $exception->getMessage());
        }
    }

    public function test_rejects_a_v1_snapshot_without_the_fiscal_party_blocks(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Snapshot v1');

        $this->builder->handle(['schema_version' => 1, 'value' => '898.45']);
    }

    public function test_allocates_distinct_numbers_across_documents(): void
    {
        $first = $this->builder->handle($this->vehicleSnapshot());
        $second = $this->builder->handle($this->vehicleSnapshot());

        $this->assertSame(900000001, $first->number);
        $this->assertSame(900000002, $second->number);
        $this->assertNotSame($first->accessKey, $second->accessKey);
    }

    public function test_vehicle_and_alcohol_documents_share_the_same_cte_number_sequence(): void
    {
        $vehicle = $this->builder->handle($this->vehicleSnapshot());
        $alcohol = $this->builder->handle($this->tankSnapshot());

        $this->assertSame(900000001, $vehicle->number);
        $this->assertSame(900000002, $alcohol->number);
    }

    public function test_production_uses_non_contributor_indicator_when_fiscal_recipient_has_no_ie(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $snapshot = $this->vehicleSnapshot();
        $snapshot['dest']['ie'] = '';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<indIEToma>9</indIEToma>', $xml);
        $this->assertStringNotContainsString('<dest><CNPJ>14517191000178</CNPJ><IE>', $xml);
    }

    #[DataProvider('senderInscriptionExamples')]
    public function test_sender_ie_preserves_the_taker_indicator_and_actual_route(?string $ie): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.numbering_start' => '123']);
        $snapshot = $this->vehicleSnapshot();
        $snapshot['rem']['ie'] = $ie;
        $snapshot['rem']['uf'] = 'PR';
        $snapshot['rem']['cMun'] = '4106902';
        $snapshot['rem']['xMun'] = 'Curitiba';
        $snapshot['rem']['cep'] = '80010000';

        $xml = $this->signed($snapshot)->xml;

        $this->assertStringContainsString('<indIEToma>1</indIEToma>', $xml);
        $this->assertStringContainsString('<UFIni>SP</UFIni>', $xml);
        $this->assertStringContainsString('<UFFim>SP</UFFim>', $xml);
        $this->assertStringContainsString('<CEP>80010000</CEP><UF>PR</UF>', $xml);
        $this->assertStringContainsString(
            '<rem><CNPJ>33164021000100</CNPJ>'.($ie === null ? '<xNome>' : '<IE>ISENTO</IE><xNome>'),
            $xml,
        );
    }

    public static function senderInscriptionExamples(): array
    {
        return [
            'exempt sender' => ['ISENTO'],
            'non contributor sender' => [null],
        ];
    }

    public function test_production_rejects_an_exempt_tomador_in_sao_paulo_before_allocating_a_number(): void
    {
        config(['dfe.tp_amb' => '1']);
        $snapshot = $this->vehicleSnapshot();
        $snapshot['dest']['ie'] = 'ISENTO';

        try {
            $this->builder->handle($snapshot);
            $this->fail('The São Paulo rule must reject an exempt tomador before number allocation.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('não pode ser informado como ISENTO', $exception->getMessage());
        }

        $this->assertDatabaseCount('cte_number_sequences', 0);
    }

    /**
     * Snapshot espelhando o gabarito autorizado 2962 (linha veículos).
     *
     * @return array<string, mixed>
     */
    protected function vehicleSnapshot(): array
    {
        return [
            'schema_version' => 2,
            'line' => 'vehicle_removal',
            'register_id' => 1,
            'value' => '898.45',
            'fipe_value' => '139329.00',
            'vehicle_model' => 'AMAROK',
            'vehicle_plate' => 'BDA4E98',
            'payment_code' => 'T718089',
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'rem' => [
                'cnpj' => '33164021000100',
                'ie' => '110794302111',
                'xNome' => 'TOKIO MARINE SEGURADORA S.A',
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
                'cMun' => '3523107',
                'xMun' => 'Itaquaquecetuba',
                'cep' => '08597230',
                'uf' => 'SP',
            ],
        ];
    }

    /**
     * Snapshot espelhando o gabarito autorizado 2963 (linha tanque).
     *
     * @return array<string, mixed>
     */
    protected function tankSnapshot(): array
    {
        return [
            'schema_version' => 2,
            'line' => 'tank_alcohol',
            'register_id' => 2,
            'value' => '3725.81',
            'cargo_value' => '54294.71',
            'cargo_liters' => '21879',
            'nfe_key' => '35260945483450002164550010004300881365784943',
            'dprev' => '2026-10-02',
            'origin' => ['ibge' => '3541802', 'name' => 'Queiroz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3525102', 'name' => 'Jardinopolis', 'uf' => 'SP'],
            'rem' => [
                'cnpj' => '45483450002164',
                'ie' => '566051683117',
                'xNome' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERACAO JUDICIAL',
                'xFant' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERAC',
                'xLgr' => 'FAZENDA POUSO ALEGRE,',
                'nro' => 'S/N',
                'xBairro' => 'ZONA RURAL',
                'cMun' => '3541802',
                'xMun' => 'Queiroz',
                'cep' => '17590000',
                'uf' => 'SP',
            ],
            'dest' => [
                'cnpj' => '48580847000208',
                'ie' => '399116523112',
                'xNome' => 'AVANT COMBUSTIVEIS LTDA',
                'xLgr' => 'R BRASIL ALTO FURQUINI',
                'nro' => '401',
                'xBairro' => 'BAIRRO DISTRITO INDUSTRIAL ADI',
                'cMun' => '3525102',
                'xMun' => 'Jardinopolis',
                'cep' => '14690000',
                'uf' => 'SP',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function generalCargoSnapshot(): array
    {
        return [
            'schema_version' => 4,
            'line' => 'general_cargo',
            'cfop' => '5353',
            'nat_op' => 'PREST SERV TRANSPORTE',
            'taker_code' => '3',
            'ind_ietoma' => '1',
            'taker_is_transport_company' => false,
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'rem' => $this->genericParty('Remetente', '123456789'),
            'dest' => $this->genericParty('Destinatário', '987654321'),
            'exped' => null,
            'receb' => null,
            'taker' => null,
            'nfe_documents' => [['access_key' => '35261011111111111111550010000000011000000011']],
            'cargo' => [
                'value' => '25000.00',
                'predominant_product' => 'MAQUINAS AGRICOLAS',
                'other_category' => 'PECAS PESADAS',
                'weight_kg' => '12000.0000',
                'measurements' => [
                    ['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.0000'],
                    ['unit' => '03', 'measure' => 'UNIDADES', 'quantity' => '4.0000'],
                ],
            ],
            'freight' => [
                'total' => '5000.00',
                'receivable' => '4800.00',
                'components' => [
                    ['name' => 'FRETE', 'value' => '4500.00'],
                    ['name' => 'PEDAGIO', 'value' => '500.00'],
                ],
            ],
            'value' => '5000.00',
        ];
    }

    /** @return array<string, string> */
    protected function genericParty(string $name, string $ie): array
    {
        return [
            'cnpj' => '11111111111111',
            'cpf' => '',
            'ie' => $ie,
            'xNome' => $name,
            'xLgr' => 'Rua das Cargas',
            'nro' => '100',
            'xBairro' => 'Centro',
            'cMun' => '3504008',
            'xMun' => 'Assis',
            'CEP' => '19800000',
            'UF' => 'SP',
        ];
    }
}
