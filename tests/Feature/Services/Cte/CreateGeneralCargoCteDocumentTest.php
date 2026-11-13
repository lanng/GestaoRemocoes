<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\City;
use App\Models\Company;
use App\Services\Cte\AssertCteNfeOriginalsArchived;
use App\Services\Cte\CreateGeneralCargoCteDocument;
use App\Services\Cte\UpdateGeneralCargoCteDraft;
use App\Services\Cte\ValidateGeneralCargoCteSnapshot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateGeneralCargoCteDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');
        $this->seedCities();
    }

    public function test_creates_a_standalone_draft_from_manual_nfe_keys_and_generic_cargo_data(): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());

        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertNull($document->cte_emission_batch_id);
        $this->assertNull($document->register_id);
        $this->assertSame(FiscalDocumentWorkflowOriginEnum::ControlledV1, $document->workflow_origin);
        $this->assertSame('direct', $document->emitter);
        $this->assertSame('live', $document->execution_mode);
        $this->assertSame('general_cargo', $document->snapshot['line']);
        $this->assertSame('25000.00', $document->snapshot['cargo']['value']);
        $this->assertSame('PESO ESTIMADO', $document->snapshot['cargo']['measurements'][0]['measure']);
        $this->assertArrayNotHasKey('weight_kg', $document->snapshot['cargo']);
        $this->assertArrayNotHasKey('cargo_weight_kg', $document->snapshot);
        $this->assertSame('750.00', $document->snapshot['freight']['total']);
        $this->assertSame('700.00', $document->snapshot['freight']['receivable']);

        $reference = $document->nfeDocuments()->sole();
        $this->assertSame('35261011111111111111550010000000011000000011', $reference->access_key);
        $this->assertNull($reference->xml_path);
        $this->assertNull($reference->sha256);
        $this->assertNull($reference->extracted_data);
    }

    public function test_creates_a_draft_with_transported_units_and_no_weight(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '03', 'measure' => 'VEICULOS', 'quantity' => '3']];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame([
            ['unit' => '03', 'measure' => 'VEICULOS', 'quantity' => '3.0000'],
        ], $document->snapshot['cargo']['measurements']);
        $this->assertArrayNotHasKey('weight_kg', $document->snapshot['cargo']);
        $this->assertArrayNotHasKey('cargo_weight_kg', $document->snapshot);
    }

    public function test_keeps_explicit_peso_bruto_as_a_measured_weight(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.4500']];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame('12000.45', $document->snapshot['cargo']['weight_kg']);
        $this->assertSame('12000.45', $document->snapshot['cargo_weight_kg']);
    }

    public function test_rejects_cte_avulso_weight_with_meaningful_precision_beyond_two_decimal_places(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.451']];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('até 2 casas decimais');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_emission_snapshot_validation_rejects_kilogram_measurements_with_meaningful_third_decimal(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.45']];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $snapshot = $document->snapshot;
        $snapshot['cargo']['measurements'][0]['quantity'] = '12000.451';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('até 2 casas decimais');

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);
    }

    public function test_emission_snapshot_validation_rejects_stored_cargo_weight_with_meaningful_third_decimal(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.45']];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $snapshot = $document->snapshot;
        $snapshot['cargo']['weight_kg'] = '12000.451';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('até 2 casas decimais');

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);
    }

    public function test_emission_snapshot_validation_rejects_top_level_cargo_weight_with_meaningful_third_decimal(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.45']];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $snapshot = $document->snapshot;
        $snapshot['cargo_weight_kg'] = '12000.451';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('até 2 casas decimais');

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);
    }

    public function test_emission_snapshot_validation_accepts_kg_values_with_only_trailing_zero_precision(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => '12000.45']];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $snapshot = $document->snapshot;
        $snapshot['cargo']['measurements'][0]['quantity'] = '12000.4500';
        $snapshot['cargo']['weight_kg'] = '12000.4500';
        $snapshot['cargo_weight_kg'] = '12000.4500';

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);

        $this->assertTrue(true);
    }

    public function test_creates_a_carta_de_remocao_origin_document_without_inventing_number_or_date(): void
    {
        $data = $this->data();
        $remitter = Company::query()->findOrFail($data['rem_company_id']);
        $data['origin_document_mode'] = 'other';
        $data['nfe_keys'] = '';
        $data['nfe_xmls'] = [];
        $data['carta_number'] = '';
        $data['carta_issued_on'] = '';

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame(5, $document->snapshot['schema_version']);
        $this->assertSame('other', $document->snapshot['origin_document_mode']);
        $this->assertSame([
            'type' => 'carta_remocao',
            'tp_doc' => '99',
            'description' => 'CARTA DE REMOCAO',
            'number' => null,
            'issued_on' => null,
            'attachment' => null,
        ], $document->snapshot['other_document']);
        $this->assertSame([], $document->nfeDocuments->all());
        $this->assertSame($remitter->id, $document->snapshot['rem']['company_id']);
        $this->assertSame('Remetente Industrial', $document->snapshot['rem']['xNome']);

        app(AssertCteNfeOriginalsArchived::class)->handle($document);
    }

    public function test_archives_optional_carta_attachment_privately_with_hash_and_original_name(): void
    {
        $data = $this->cartaData();
        $contents = "%PDF-1.4\n% carta de remoção de teste\n";
        $data['carta_attachment'] = ['filename' => 'carta original.pdf', 'contents' => $contents];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $attachment = $document->snapshot['other_document']['attachment'];

        $this->assertSame('carta original.pdf', $attachment['filename']);
        $this->assertSame(hash('sha256', $contents), $attachment['sha256']);
        $this->assertStringStartsWith('cte-drafts/'.$document->public_id.'/carta-remocao/', $attachment['path']);
        $this->assertSame($contents, Storage::disk('dfe')->get($attachment['path']));

        app(AssertCteNfeOriginalsArchived::class)->handle($document);
    }

    public function test_rejects_nfe_references_when_carta_mode_is_selected(): void
    {
        $data = $this->cartaData();
        $data['nfe_keys'] = '35261011111111111111550010000000011000000011';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Carta de remoção não pode ser combinada com referências de NF-e');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_rejects_an_attachment_that_is_not_a_supported_document_file(): void
    {
        $data = $this->cartaData();
        $data['carta_attachment'] = ['filename' => 'carta.pdf', 'contents' => 'conteúdo de texto sem formato PDF'];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PDF, JPEG ou PNG');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_rejects_a_selected_company_with_incomplete_fiscal_address_data(): void
    {
        $data = $this->data();
        Company::query()->findOrFail($data['rem_company_id'])->update(['street' => '']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('xLgr de remetente');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_rejects_a_carta_attachment_larger_than_ten_megabytes(): void
    {
        $data = $this->cartaData();
        $data['carta_attachment'] = [
            'filename' => 'carta.pdf',
            'contents' => "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024),
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no máximo 10 MB');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_archives_uploaded_xml_without_applying_alcohol_totals_or_quantity_rules(): void
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml'));
        $xml = str_replace('<vNF>1000.00</vNF>', '<vNF>1000.01</vNF>', $xml);
        $xml = str_replace('<pesoL>80.000</pesoL><pesoB>80.000</pesoB>', '<pesoL>79.000</pesoL><pesoB>80.000</pesoB>', $xml);
        $xml = preg_replace('/\s*<protNFe\b.*?<\/protNFe>/s', '', $xml);
        $data = $this->data();
        $data['nfe_keys'] = '';
        $data['nfe_xmls'] = [['filename' => 'generic.xml', 'xml' => $xml]];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $reference = $document->nfeDocuments()->sole();

        $this->assertSame('35261011111111111111550010000000011000000011', $reference->access_key);
        $this->assertSame(hash('sha256', $xml), $reference->sha256);
        $this->assertSame($xml, Storage::disk('dfe')->get($reference->xml_path));
        $this->assertSame('1000.01', $reference->extracted_data['cargo_value']);
        $this->assertSame('80.0000', $reference->extracted_data['gross_weight_kg']);
        $this->assertSame('79.0000', $reference->extracted_data['net_weight_kg']);
        $this->assertSame('22071090', $reference->extracted_data['products'][0]['ncm']);
        $this->assertSame('ETANOL HIDRATADO COMBUSTIVEL - TESTE', $reference->extracted_data['products'][0]['name']);
        $this->assertSame('04', $reference->extracted_data['measurements'][2]['unit']);
        $this->assertSame('100.0000', $reference->extracted_data['measurements'][2]['quantity']);
    }

    public function test_rejects_nfe_xmls_over_the_aggregate_import_size_limit(): void
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml'));
        config(['dfe.nfe_xml_max_total_size_bytes' => strlen($xml) - 1]);
        $data = $this->data();
        $data['nfe_keys'] = '';
        $data['nfe_xmls'] = [['filename' => 'entrada.xml', 'xml' => $xml]];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('totalizar');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_nfe_m3_quantity_remains_cubic_meters_in_the_general_cargo_import(): void
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml'));
        $xml = str_replace(
            '<uCom>L</uCom><qCom>100.0000</qCom><vUnCom>10.0000000000</vUnCom><vProd>1000.00</vProd><cEANTrib>SEM GTIN</cEANTrib><uTrib>L</uTrib><qTrib>100.0000</qTrib>',
            '<uCom>M3</uCom><qCom>0.1000</qCom><vUnCom>10000.0000000000</vUnCom><vProd>1000.00</vProd><cEANTrib>SEM GTIN</cEANTrib><uTrib>M3</uTrib><qTrib>0.1000</qTrib>',
            $xml,
        );
        $data = $this->data();
        $data['nfe_keys'] = '';
        $data['nfe_xmls'] = [['filename' => 'carga-m3.xml', 'xml' => $xml]];

        $reference = app(CreateGeneralCargoCteDocument::class)->handle($data)->nfeDocuments()->sole();
        $commercialMeasurement = collect($reference->extracted_data['measurements'])->firstWhere('measure', 'QTD COMERCIAL');

        $this->assertSame('00', $commercialMeasurement['unit']);
        $this->assertSame('0.1000', $commercialMeasurement['quantity']);
        $this->assertSame('M3', $reference->extracted_data['products'][0]['unit']);
        $this->assertSame('0.1000', $reference->extracted_data['products'][0]['quantity']);
    }

    #[DataProvider('validTransportCfops')]
    public function test_accepts_only_transport_cfops_compatible_with_the_route(
        string $originIbge,
        string $originName,
        string $originUf,
        string $destinationIbge,
        string $destinationName,
        string $destinationUf,
        string $cfop,
    ): void {
        $data = $this->data();
        $data['cfop'] = $cfop;
        $data['origin'] = ['ibge' => $originIbge, 'name' => $originName, 'uf' => $originUf];
        $data['destination'] = ['ibge' => $destinationIbge, 'name' => $destinationName, 'uf' => $destinationUf];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame($cfop, $document->snapshot['cfop']);
    }

    public static function validTransportCfops(): array
    {
        return [
            '5352 industrial within SP' => ['3504008', 'Assis', 'SP', '3523107', 'Itaquaquecetuba', 'SP', '5352'],
            '5353 commercial within SP' => ['3504008', 'Assis', 'SP', '3523107', 'Itaquaquecetuba', 'SP', '5353'],
            '6352 industrial across states' => ['3504008', 'Assis', 'SP', '3106200', 'Belo Horizonte', 'MG', '6352'],
            '6353 commercial across states' => ['3504008', 'Assis', 'SP', '3106200', 'Belo Horizonte', 'MG', '6353'],
            '6932 starts outside issuer UF' => ['3106200', 'Belo Horizonte', 'MG', '3523107', 'Itaquaquecetuba', 'SP', '6932'],
            '5932 outside issuer within same state' => ['3106200', 'Belo Horizonte', 'MG', '3106200', 'Belo Horizonte', 'MG', '5932'],
        ];
    }

    #[DataProvider('invalidTransportCfops')]
    public function test_rejects_cfops_that_are_not_transport_codes_or_do_not_match_the_route(
        string $originIbge,
        string $originName,
        string $originUf,
        string $destinationIbge,
        string $destinationName,
        string $destinationUf,
        string $cfop,
    ): void {
        $data = $this->data();
        $data['cfop'] = $cfop;
        $data['origin'] = ['ibge' => $originIbge, 'name' => $originName, 'uf' => $originUf];
        $data['destination'] = ['ibge' => $destinationIbge, 'name' => $destinationName, 'uf' => $destinationUf];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CFOP');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public static function invalidTransportCfops(): array
    {
        return [
            'sales CFOP within SP' => ['3504008', 'Assis', 'SP', '3523107', 'Itaquaquecetuba', 'SP', '5102'],
            'sales CFOP across states' => ['3504008', 'Assis', 'SP', '3106200', 'Belo Horizonte', 'MG', '6102'],
            'same-state prefix on interstate route' => ['3504008', 'Assis', 'SP', '3106200', 'Belo Horizonte', 'MG', '5353'],
            'interstate prefix on same-state route' => ['3504008', 'Assis', 'SP', '3523107', 'Itaquaquecetuba', 'SP', '6353'],
        ];
    }

    public function test_uses_an_explicit_taker_icms_status_instead_of_inferring_it_from_ie(): void
    {
        $data = $this->data();
        $data['ind_ietoma'] = '9';

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame('9', $document->snapshot['ind_ietoma']);
    }

    public function test_requires_ie_for_a_taker_marked_as_icms_contributor(): void
    {
        $data = $this->data();
        Company::query()->findOrFail($data['rem_company_id'])->update(['ie' => '']);
        $data['taker_code'] = '0';
        $data['ind_ietoma'] = '1';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inscrição estadual numérica');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    #[DataProvider('restrictedIsentoStates')]
    public function test_blocks_isento_tomador_based_on_the_selected_partys_uf(string $state): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());
        $snapshot = $document->snapshot;
        $snapshot['taker_code'] = '0';
        $snapshot['ind_ietoma'] = '2';
        $snapshot['rem']['UF'] = $state;
        $snapshot['rem']['ie'] = 'ISENTO';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('não permite tomador isento');

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);
    }

    public static function restrictedIsentoStates(): array
    {
        return array_combine(
            ['AM', 'BA', 'CE', 'GO', 'MG', 'MS', 'MT', 'PE', 'RN', 'SE', 'SP'],
            array_map(fn (string $state): array => [$state], ['AM', 'BA', 'CE', 'GO', 'MG', 'MS', 'MT', 'PE', 'RN', 'SE', 'SP']),
        );
    }

    public function test_allows_isento_tomador_in_a_state_not_restricted_by_the_moc(): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());
        $snapshot = $document->snapshot;
        $snapshot['taker_code'] = '0';
        $snapshot['ind_ietoma'] = '2';
        $snapshot['rem']['UF'] = 'PR';
        $snapshot['rem']['ie'] = 'ISENTO';

        app(ValidateGeneralCargoCteSnapshot::class)->handle($snapshot, forEmission: true);

        $this->assertSame('2', $snapshot['ind_ietoma']);
    }

    public function test_edits_a_general_cargo_draft_without_changing_archived_nfe_originals(): void
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml'));
        $data = $this->data();
        $data['cfop'] = '';
        $data['origin'] = ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'];
        $data['destination'] = ['ibge' => '3106200', 'name' => 'Belo Horizonte', 'uf' => 'MG'];
        $data['nfe_keys'] = '';
        $data['nfe_xmls'] = [['filename' => 'original.xml', 'xml' => $xml]];
        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);
        $original = $document->nfeDocuments()->sole();

        $updated = app(UpdateGeneralCargoCteDraft::class)->handle($document, [
            'cfop' => '6353',
            'cargo' => [
                'value' => '1200.00',
                'predominant_product' => 'CARGA REVISADA',
                'other_category' => 'VOLUMES DIVERSOS',
                'measurements' => [
                    ['unit' => '03', 'measure' => 'UNIDADES', 'quantity' => '2'],
                ],
            ],
        ]);
        $archivedNfe = $updated->nfeDocuments()->sole();

        $this->assertSame(CteDocumentStatusEnum::DRAFT, $updated->status);
        $this->assertSame('6353', $updated->snapshot['cfop']);
        $this->assertSame('1200.00', $updated->snapshot['cargo']['value']);
        $this->assertSame('1200.00', $updated->snapshot['cargo_value']);
        $this->assertArrayNotHasKey('weight_kg', $updated->snapshot['cargo']);
        $this->assertArrayNotHasKey('cargo_weight_kg', $updated->snapshot);
        $this->assertSame('UNIDADES', $updated->snapshot['cargo']['measurements'][0]['measure']);
        $this->assertSame($original->id, $archivedNfe->id);
        $this->assertSame($original->access_key, $archivedNfe->access_key);
        $this->assertSame($original->xml_path, $archivedNfe->xml_path);
        $this->assertSame($original->sha256, $archivedNfe->sha256);
        $this->assertSame($original->extracted_data, $archivedNfe->extracted_data);
        $this->assertSame($xml, Storage::disk('dfe')->get($archivedNfe->xml_path));
    }

    public function test_rejects_editing_cte_avulso_weight_with_more_than_two_decimal_places(): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('até 2 casas decimais');

        app(UpdateGeneralCargoCteDraft::class)->handle($document, [
            'cfop' => '5353',
            'cargo' => [
                'value' => '1200.00',
                'predominant_product' => 'CARGA REVISADA',
                'other_category' => '',
                'measurements' => [['unit' => '01', 'measure' => 'PESO ESTIMADO', 'quantity' => '12000.451']],
            ],
        ]);
    }

    public function test_rejects_measurements_that_are_neither_estimated_kg_nor_transported_units(): void
    {
        $data = $this->data();
        $data['cargo']['measurements'] = [['unit' => '03', 'measure' => 'VOLUMES', 'quantity' => '2']];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('peso estimado em kg ou quantidade transportada');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    public function test_rejects_editing_a_queued_general_cargo_document(): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());
        $document->forceFill(['status' => CteDocumentStatusEnum::QUEUED])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Somente rascunhos avulsos');

        app(UpdateGeneralCargoCteDraft::class)->handle($document, []);
    }

    public function test_rejects_editing_a_general_cargo_draft_with_transmission_evidence(): void
    {
        $document = app(CreateGeneralCargoCteDocument::class)->handle($this->data());
        $document->forceFill(['payload_xml' => '<CTe/>'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Somente rascunhos avulsos');

        app(UpdateGeneralCargoCteDraft::class)->handle($document, []);
    }

    public function test_rejects_duplicate_manual_nfe_keys(): void
    {
        $data = $this->data();
        $data['nfe_keys'] .= "\n35261011111111111111550010000000011000000011";

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('duplicada');

        app(CreateGeneralCargoCteDocument::class)->handle($data);
    }

    #[DataProvider('routeCfopSuggestions')]
    public function test_suggests_cfop_from_the_physical_route(
        string $originIbge,
        string $originName,
        string $originUf,
        string $destinationIbge,
        string $destinationName,
        string $destinationUf,
        string $expected,
    ): void {
        $data = $this->data();
        $data['cfop'] = '';
        $data['origin'] = ['ibge' => $originIbge, 'name' => $originName, 'uf' => $originUf];
        $data['destination'] = ['ibge' => $destinationIbge, 'name' => $destinationName, 'uf' => $destinationUf];

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame($expected, $document->snapshot['cfop']);
    }

    public function test_uses_city_database_values_instead_of_trusting_submitted_names_and_ufs(): void
    {
        $data = $this->data();
        $data['origin'] = ['ibge' => '3504008', 'name' => 'Cidade adulterada', 'uf' => 'RJ'];
        $data['cfop'] = '5353';

        $document = app(CreateGeneralCargoCteDocument::class)->handle($data);

        $this->assertSame(['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'], $document->snapshot['origin']);
    }

    public static function routeCfopSuggestions(): array
    {
        return [
            'SP to SP' => ['3504008', 'Assis', 'SP', '3523107', 'Itaquaquecetuba', 'SP', '5353'],
            'outside SP within same state' => ['3106200', 'Belo Horizonte', 'MG', '3106200', 'Belo Horizonte', 'MG', '5932'],
            'outside SP across states' => ['3106200', 'Belo Horizonte', 'MG', '3304557', 'Rio de Janeiro', 'RJ', '6932'],
            'SP to another state needs manual CFOP' => ['3504008', 'Assis', 'SP', '3106200', 'Belo Horizonte', 'MG', ''],
        ];
    }

    protected function seedCities(): void
    {
        foreach ([
            ['3504008', 'Assis', 'SP'],
            ['3523107', 'Itaquaquecetuba', 'SP'],
            ['3106200', 'Belo Horizonte', 'MG'],
            ['3304557', 'Rio de Janeiro', 'RJ'],
        ] as [$ibge, $name, $state]) {
            City::query()->create(['ibge_code' => $ibge, 'name' => $name, 'state' => $state]);
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $remitter = $this->company('11111111111111', 'Remetente Industrial', '3504008', 'Assis');
        $recipient = $this->company('22222222222222', 'Destinatário Comercial', '3523107', 'Itaquaquecetuba');

        return [
            'origin_document_mode' => 'nfe',
            'rem_company_id' => $remitter->id,
            'dest_company_id' => $recipient->id,
            'cfop' => '5353',
            'nat_op' => 'PREST SERV TRANSPORTE',
            'taker_code' => '3',
            'ind_ietoma' => '1',
            'taker_is_transport_company' => false,
            'nfe_keys' => '35261011111111111111550010000000011000000011',
            'nfe_xmls' => [],
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'exped' => null,
            'receb' => null,
            'taker' => null,
            'cargo' => [
                'value' => '25000.00',
                'predominant_product' => 'MAQUINAS AGRICOLAS',
                'other_category' => 'PEÇAS PESADAS',
                'measurements' => [['unit' => '01', 'measure' => 'PESO ESTIMADO', 'quantity' => '12000.0000']],
            ],
            'freight' => [
                'total' => '750.00',
                'receivable' => '700.00',
                'components' => [['name' => 'FRETE', 'value' => '750.00']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cartaData(): array
    {
        $data = $this->data();
        $data['origin_document_mode'] = 'other';
        $data['nfe_keys'] = '';

        return $data;
    }

    private function company(string $cnpj, string $name, string $ibge, string $city): Company
    {
        return Company::factory()->create([
            'cnpj' => $cnpj,
            'name' => $name,
            'ie' => '123456789',
            'street' => 'Rua das Cargas',
            'number' => '100',
            'complement' => '',
            'district' => 'Centro',
            'city' => $city,
            'state' => 'SP',
            'zipcode' => '01001000',
            'ibge_code' => $ibge,
        ]);
    }

    /** @return array<string, string> */
    private function party(string $cnpj, string $name, string $ibge, string $city): array
    {
        return [
            'cnpj' => $cnpj,
            'cpf' => '',
            'ie' => '123456789',
            'name' => $name,
            'street' => 'Rua das Cargas',
            'number' => '100',
            'complement' => '',
            'district' => 'Centro',
            'ibge' => $ibge,
            'city' => $city,
            'zipcode' => '01001000',
            'state' => 'SP',
        ];
    }
}
