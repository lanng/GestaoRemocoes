<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\BuildMdfeInsuranceSnapshot;
use App\Services\Dfe\BuildMdfeProc;
use App\Services\Dfe\BuildMdfeXml;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class BuildMdfeXmlTest extends TestCase
{
    use RefreshDatabase;

    protected XmlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
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
            'dfe.mdfe.insurer_name' => 'Seguradora Real',
            'dfe.mdfe.insurer_cnpj' => '11222333000181',
            'dfe.mdfe.policy_number' => 'APOLICE-001',
            'dfe.mdfe' => [
                'version' => '3.00',
                'series' => '1',
                'tp_emit' => '1',
                'tp_emis' => '1',
                'rntrc' => '45963122',
                'numbering_start' => 900000001,
                'xsd_path' => resource_path('xsds/mdfe'),
                'qr_base_url' => 'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode',
                'insurer_name' => 'Seguradora Real',
                'insurer_cnpj' => '11222333000181',
                'policy_number' => 'APOLICE-001',
            ],
        ]);

        $certificate = DfeTestCertificate::create();
        $this->signer = new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password']));
    }

    public function test_loading_city_ibge_must_match_uf_ini_before_a_number_is_allocated(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['loading_cities'] = [['ibge' => '4124103', 'name' => 'Santo Antônio da Platina']];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-TEST']);

        try {
            app(BuildMdfeXml::class)->handle($snapshot);
            $this->fail('A municipality in PR must not be emitted with UFIni SP.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Santo Antônio da Platina', $exception->getMessage());
            $this->assertStringContainsString('SP', $exception->getMessage());
        }

        $this->assertDatabaseCount('mdfe_number_sequences', 0);
    }

    public function test_malformed_loading_city_ibge_is_not_sanitized_before_number_allocation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['loading_cities'] = [['ibge' => '35X49805', 'name' => 'São José do Rio Preto']];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-TEST']);

        try {
            app(BuildMdfeXml::class)->handle($snapshot);
            $this->fail('Malformed municipality IBGE must not be emitted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('São José do Rio Preto', $exception->getMessage());
        }

        $this->assertDatabaseCount('mdfe_number_sequences', 0);
    }

    public function test_noncanonical_uf_ini_is_rejected_before_number_allocation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['origin']['uf'] = 'sp';
        $snapshot['route_ufs'] = ['SP'];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-TEST']);

        try {
            app(BuildMdfeXml::class)->handle($snapshot);
            $this->fail('UFIni must be validated exactly as it is rendered.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sp', $exception->getMessage());
        }

        $this->assertDatabaseCount('mdfe_number_sequences', 0);
    }

    public function test_loading_city_ibge_is_not_trimmed_before_validation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['loading_cities'] = [['ibge' => ' 3549805 ', 'name' => 'São José do Rio Preto']];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-TEST']);

        try {
            app(BuildMdfeXml::class)->handle($snapshot);
            $this->fail('Whitespace in the rendered municipality IBGE must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('São José do Rio Preto', $exception->getMessage());
        }

        $this->assertDatabaseCount('mdfe_number_sequences', 0);
    }

    public function test_loading_city_may_match_a_non_emitter_start_uf(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['origin'] = ['ibge' => '4124103', 'name' => 'Santo Antônio da Platina', 'uf' => 'PR'];
        $snapshot['loading_cities'] = [['ibge' => '4124103', 'name' => 'Santo Antônio da Platina']];
        $snapshot['route_ufs'] = ['SP'];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-TEST']);

        $built = app(BuildMdfeXml::class)->handle($snapshot, number: 900000001, series: 1, numericCode: '12345678');

        $this->assertStringContainsString('<UFIni>PR</UFIni>', $built->xml);
        $this->assertStringContainsString('<cMunCarrega>4124103</cMunCarrega>', $built->xml);
        $this->assertDatabaseCount('mdfe_number_sequences', 0);
    }

    public function test_builds_a_signed_xsd_valid_mdfe_with_real_ciot_and_insurance_fixture(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            [
                'insurer_name' => 'Seguradora Fixture',
                'insurer_cnpj' => '11111111111111',
                'policy_number' => 'FIXTURE-001',
            ],
        );

        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign(
            $built->xml,
            'infMDFe',
            'http://www.portalfiscal.inf.br/mdfe',
            'MDFe'.$built->accessKey,
        );

        app(XsdValidator::class)->validateMdfe($signedXml);

        $this->assertSame(900000001, $built->number);
        $this->assertSame(1, $built->series);
        $this->assertSame(44, strlen($built->accessKey));
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $built->xml);
        $this->assertStringContainsString('<tpEmit>1</tpEmit>', $built->xml);
        $this->assertStringContainsString('<verProc>Sistema interno 1.0</verProc>', $built->xml);
        $this->assertStringNotContainsString('<tpTransp', $built->xml);
        $this->assertStringContainsString(
            '<prodPred><tpCarga>05</tpCarga><xProd>VEICULOS</xProd><infLotacao><infLocalCarrega><CEP>14810539</CEP></infLocalCarrega><infLocalDescarrega><CEP>06550000</CEP></infLocalDescarrega></infLotacao></prodPred>',
            $built->xml,
        );
        $this->assertStringContainsString(
            '<infPag><xNome>COPART DO BRASIL ORGANIZACAO DE LEILOES LTDA</xNome><CNPJ>14517191000330</CNPJ>'
                .'<Comp><tpComp>04</tpComp><vComp>5000.00</vComp></Comp><vContrato>5000.00</vContrato>'
                .'<indPag>0</indPag><infBanc><codBanco>104</codBanco><codAgencia>0276</codAgencia></infBanc></infPag>',
            $built->xml,
        );
        $this->assertStringContainsString('<CIOT>520032956638</CIOT><CNPJ>12563112000130</CNPJ>', $built->xml);
        $this->assertLessThan(strpos($built->xml, '<infCIOT>'), strpos($built->xml, '<RNTRC>'));
        $this->assertStringContainsString('<respSeg>1</respSeg>', $built->xml);
        $this->assertStringContainsString('<xSeg>Seguradora Fixture</xSeg><CNPJ>11111111111111</CNPJ>', $built->xml);
        $this->assertStringContainsString('<nApol>FIXTURE-001</nApol><nAver>AVERBACAO-FIXTURE-001</nAver>', $built->xml);
        $this->assertStringContainsString('<qrCodMDFe>https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode?chMDFe='.$built->accessKey.'&amp;tpAmb=2</qrCodMDFe>', $built->xml);
        $this->assertTrue($this->signer->verify($signedXml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey));
    }

    public function test_serializes_optional_predominant_product_ncm_before_lotation_details(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['cargo']['ncm'] = '87033210';
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );

        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign($built->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey);

        app(XsdValidator::class)->validateMdfe($signedXml);

        $this->assertStringContainsString(
            '<prodPred><tpCarga>05</tpCarga><xProd>VEICULOS</xProd><NCM>87033210</NCM><infLotacao>',
            $built->xml,
        );

        $tankSnapshot = $this->snapshot();
        $tankSnapshot['cargo']['predominant_product'] = 'ALCOOL ETILICO';
        $tankSnapshot['cargo']['type'] = '08';
        $tankSnapshot['cargo']['ncm'] = '22071090';
        $tankSnapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );

        $tankBuilt = app(BuildMdfeXml::class)->handle($tankSnapshot);
        $tankSignedXml = $this->signer->sign($tankBuilt->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$tankBuilt->accessKey);

        app(XsdValidator::class)->validateMdfe($tankSignedXml);

        $this->assertStringContainsString(
            '<prodPred><tpCarga>08</tpCarga><xProd>ALCOOL ETILICO</xProd><NCM>22071090</NCM><infLotacao>',
            $tankBuilt->xml,
        );
    }

    public function test_places_trailers_as_siblings_after_the_tractor_in_the_road_modal(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['vehicle']['trailers'] = [[
            'plate' => 'TIX7D32',
            'renavam' => '01406695251',
            'tare_kg' => 5000,
            'capacity_kg' => 60000,
            'capacity_m3' => null,
            'body_type' => '01',
            'uf' => 'SP',
        ]];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );

        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign($built->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey);

        app(XsdValidator::class)->validateMdfe($signedXml);

        $this->assertMatchesRegularExpression('/<veicTracao>.*?<\/veicTracao><veicReboque>.*?<\/veicReboque>/s', $built->xml);
    }

    public function test_modal_schema_validation_rejects_a_trailer_nested_inside_the_tractor(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['vehicle']['trailers'] = [[
            'plate' => 'TIX7D32',
            'renavam' => '01406695251',
            'tare_kg' => 5000,
            'capacity_kg' => 60000,
            'capacity_m3' => null,
            'body_type' => '01',
            'uf' => 'SP',
        ]];
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );

        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign($built->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey);
        $roadXml = str_replace('</veicReboque></veicTracao>', '</veicTracao><veicReboque>', $signedXml);
        $invalidModalXml = str_replace('</veicTracao><veicReboque>', '<veicReboque>', $roadXml);
        $invalidModalXml = str_replace('</veicReboque>', '</veicReboque></veicTracao>', $invalidModalXml);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mdfeModalRodoviario_v3.00.xsd');

        app(XsdValidator::class)->validateMdfe($invalidModalXml);
    }

    public function test_omits_inf_lotacao_when_infdoc_contains_multiple_ctes(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['destinations'][0]['cte_access_keys'][] = '35261012563112000130570019000000061960595781';
        $snapshot['inf_lotacao'] = null;
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );

        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign($built->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey);

        app(XsdValidator::class)->validateMdfe($signedXml);

        $this->assertStringNotContainsString('<infLotacao>', $built->xml);
        $this->assertStringContainsString('<qCTe>2</qCTe>', $built->xml);
    }

    public function test_blocks_homologation_insurance_fixture_when_production_is_selected(): void
    {
        config(['dfe.tp_amb' => '1', 'dfe.mdfe.numbering_start' => 123]);
        $snapshot = $this->snapshot();
        $snapshot['insurance'] = [
            'insurer_name' => 'Seguradora Fixture',
            'insurer_cnpj' => '11111111111111',
            'policy_number' => 'FIXTURE-001',
            'endorsements' => ['AVERBACAO-FIXTURE-001'],
            'source' => 'homologation_fixture',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('somente em homologação');

        app(BuildMdfeXml::class)->handle($snapshot);
    }

    public function test_rejects_an_operational_snapshot_without_insurance_endorsements(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['insurance'] = [
            'insurer_name' => 'Seguradora Real',
            'insurer_cnpj' => '11222333000181',
            'policy_number' => 'APOLICE-001',
            'endorsements' => [],
            'source' => 'configured',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nAver');

        app(BuildMdfeXml::class)->handle($snapshot);
    }

    public function test_builds_an_xsd_valid_mdfe_proc_from_signed_xml_and_authorization_protocol(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['insurance'] = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE-001'],
            ['insurer_name' => 'Seguradora Fixture', 'insurer_cnpj' => '11111111111111', 'policy_number' => 'FIXTURE-001'],
        );
        $built = app(BuildMdfeXml::class)->handle($snapshot);
        $signedXml = $this->signer->sign($built->xml, 'infMDFe', 'http://www.portalfiscal.inf.br/mdfe', 'MDFe'.$built->accessKey);
        $protocolXml = '<retMDFeRecepcaoSinc xmlns="http://www.portalfiscal.inf.br/mdfe"><protMDFe versao="3.00"><infProt>'
            .'<tpAmb>2</tpAmb><verAplic>SVRS1</verAplic><chMDFe>'.$built->accessKey.'</chMDFe>'
            .'<dhRecbto>2026-10-05T12:00:00-03:00</dhRecbto><nProt>135260000000001</nProt>'
            .'<cStat>100</cStat><xMotivo>Autorizado o uso do MDF-e</xMotivo>'
            .'</infProt></protMDFe></retMDFeRecepcaoSinc>';

        $proc = app(BuildMdfeProc::class)->handle($signedXml, $protocolXml);

        $this->assertStringContainsString('<mdfeProc', $proc);
        $this->assertStringContainsString('<protMDFe', $proc);
        $this->assertStringContainsString('<chMDFe>'.$built->accessKey.'</chMDFe>', $proc);
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(): array
    {
        return [
            'issue_at' => '2026-10-05T12:00:00-03:00',
            'tp_emit' => '1',
            'ciot' => '520032956638',
            'ciot_responsible_cnpj' => '12563112000130',
            'rntrc' => '45963122',
            'origin' => ['ibge' => '3534609', 'name' => 'Osvaldo Cruz', 'uf' => 'SP'],
            'destination' => ['ibge' => '3508504', 'name' => 'Caçapava', 'uf' => 'SP'],
            'loading_cities' => [['ibge' => '3534609', 'name' => 'Osvaldo Cruz']],
            'route_ufs' => [],
            'destinations' => [[
                'ibge' => '3508504',
                'name' => 'Caçapava',
                'cte_access_keys' => ['35260912563112000130570010000028171839975374'],
            ]],
            'vehicle' => [
                'plate' => 'ABC1234',
                'renavam' => '12345678901',
                'tare_kg' => 8000,
                'capacity_kg' => 12000,
                'capacity_m3' => null,
                'type_rod' => '03',
                'body_type' => '00',
                'uf' => 'SP',
                'drivers' => [['name' => 'Motorista Fixture', 'cpf' => '12345678909']],
                'trailers' => [],
            ],
            'cargo' => [
                'value' => '50000.00',
                'weight_kg' => '4000.0000',
                'predominant_product' => 'VEICULOS',
                'type' => '05',
            ],
            'inf_lotacao' => [
                'loading_cep' => '14810539',
                'unloading_cep' => '06550000',
            ],
            'payment' => [
                'payer_name' => 'COPART DO BRASIL ORGANIZACAO DE LEILOES LTDA',
                'payer_cnpj' => '14517191000330',
                'components' => [['type' => '04', 'value' => '5000.00']],
                'contract_value' => '5000.00',
                'method' => '0',
                'bank_code' => '104',
                'bank_agency' => '0276',
            ],
        ];
    }
}
