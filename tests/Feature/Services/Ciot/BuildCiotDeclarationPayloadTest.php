<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotOperationTypeEnum;
use App\Models\Ciot;
use App\Services\Ciot\BuildCiotDeclarationPayload;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildCiotDeclarationPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ciot.natureza_fallback' => false,
            'ciot.company' => [
                'cnpj' => '12563112000130',
                'rntrc' => '045963122',
                'name' => 'FERNANDES E FERNANDES LTDA',
            ],
            'ciot.lines.vehicle_removal' => [
                'bank_code' => '756',
                'bank_agency' => '0001',
                'bank_account' => '111',
            ],
            'ciot.lines.tank_alcohol' => [
                'bank_code' => '756',
                'bank_agency' => '0002',
                'bank_account' => '222',
            ],
            'ciot.defaults' => [
                'ind_alto_desempenho' => true,
                'ind_retorno_vazio' => true,
                'composicao_veicular' => true,
            ],
        ]);
    }

    public function test_builds_the_declaration_for_a_removal_lotation_ciot(): void
    {
        $ciot = Ciot::factory()->create([
            'line' => CiotLineEnum::VehicleRemoval,
            'operation_type' => CiotOperationTypeEnum::Lotation,
            'payer_cnpj' => '14517191000925',
            'delivery_payer_cnpj' => '14517191000410',
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
                ['placa' => 'TIX7D32', 'rntrc' => '045963122', 'eixos' => 2, 'tipo' => 'reboque'],
            ],
            'distance_km' => '716.00',
            'freight_value_cents' => 100000,
            'cargo_weight_kg' => '20000.00',
            'travel_start_at' => now()->addDay(),
            'travel_end_at' => now()->addDays(2),
        ]);

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $this->assertSame('12563112000130', $payload['cpfCnpj']);
        $this->assertArrayNotHasKey('IdOperacaoTransporte', $payload);
        $this->assertSame(1000.0, $payload['ValorFrete']);
        $this->assertFalse($payload['IndContingencia']);
        $this->assertSame($ciot->travel_start_at->format('Y-m-d'), $payload['DataInicioViagem']);
        $this->assertSame($ciot->travel_end_at->format('Y-m-d'), $payload['DataFimViagem']);
        $this->assertSame(3534609, $payload['OrigemDestino'][0]['Origem']['CodigoMunicipioOrigem']);
        $this->assertSame(1, $payload['TipoOperacao']);
        $this->assertSame('12563112000130', $payload['CpfCnpjContratado']);
        $this->assertSame('045963122', $payload['RNTRCContratado']);
        $this->assertSame('', $payload['RNTRCContratante']);
        $this->assertSame('14517191000925', $payload['CpfCnpjContratante']);
        $this->assertSame('14517191000410', $payload['CpfCnpjDestinatario']);

        $this->assertSame([
            ['Placa' => 'PUC8E55', 'rntrc' => '045963122', 'numeroEixos' => 3],
            ['Placa' => 'TIX7D32', 'rntrc' => '045963122', 'numeroEixos' => 2],
        ], $payload['Veiculos']);

        $route = $payload['OrigemDestino'][0];
        $this->assertSame(['CodigoMunicipioOrigem' => 3534609], $route['Origem']);
        $this->assertSame(['CodigoMunicipioDestino' => 3508504], $route['Destino']);
        $this->assertArrayNotHasKey('CepOrigem', $route['Origem']);
        $this->assertSame(716.0, $route['DistanciaPercorrida']);

        $this->assertSame(1, $payload['DadosCarga']['CodigoNaturezaCarga']);
        $this->assertSame(5, $payload['DadosCarga']['CodigoTipoCarga']);
        $this->assertSame(20000.0, $payload['DadosCarga']['PesoCarga']);
        // B64: proibido na lotação — nenhuma grafia da chave pode existir.
        $this->assertArrayNotHasKey('ContratantesCargFrac', $payload['DadosCarga']);
        $this->assertArrayNotHasKey('ContratantesCargaFrac', $payload['DadosCarga']);

        $this->assertSame([
            'TipoPagamento' => 2,
            'CodigoInstituicaoFinanceira' => '756',
            'NumeroAgencia' => '0001',
            'NumeroConta' => '111',
            'CpfCnpjCreditado' => '12563112000130',
            'IndPagamento' => 0,
        ], $payload['InfPagamento'][0]);

        $this->assertSame([
            'IndAltoDesempenho' => true,
            'IndRetornoVazio' => true,
            'ComposicaoVeicular' => true,
        ], $payload['InfIndicadoresOperacionais']);
    }

    public function test_uses_the_tank_line_payment_and_natureza(): void
    {
        $ciot = Ciot::factory()->tankAlcohol()->create([
            'operation_type' => CiotOperationTypeEnum::Lotation,
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
        ]);

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $this->assertSame(3, $payload['DadosCarga']['CodigoNaturezaCarga']);
        $this->assertSame(2, $payload['DadosCarga']['CodigoTipoCarga']);
        $this->assertSame('0002', $payload['InfPagamento'][0]['NumeroAgencia']);
        $this->assertSame('222', $payload['InfPagamento'][0]['NumeroConta']);
    }

    public function test_general_cargo_uses_the_removal_operational_profile_without_changing_its_ciots_line(): void
    {
        $line = CiotLineEnum::GeneralCargo;
        $ciot = Ciot::factory()->create([
            'line' => $line,
            'operation_type' => CiotOperationTypeEnum::Lotation,
            'payer_cnpj' => '14517191000925',
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
            'distance_km' => '716.00',
            'freight_value_cents' => 100000,
            'cargo_weight_kg' => '1234.56',
            'travel_start_at' => now()->addDay(),
            'travel_end_at' => now()->addDays(2),
        ]);

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $this->assertSame(CiotLineEnum::VehicleRemoval, $line->operationalProfile());
        $this->assertSame('vehicle_removal', $line->configKey());
        $this->assertSame(1, $payload['DadosCarga']['CodigoNaturezaCarga']);
        $this->assertSame(5, $payload['DadosCarga']['CodigoTipoCarga']);
        $this->assertSame(1234.56, $payload['DadosCarga']['PesoCarga']);
        $this->assertSame('0001', $payload['InfPagamento'][0]['NumeroAgencia']);
        $this->assertSame(CiotLineEnum::GeneralCargo, $ciot->fresh()->line);
    }

    public function test_natureza_fallback_preserves_the_line_cargo_type(): void
    {
        config(['ciot.natureza_fallback' => true]);

        $ciot = Ciot::factory()->create();

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $this->assertSame(1, $payload['DadosCarga']['CodigoNaturezaCarga']);
        $this->assertSame(5, $payload['DadosCarga']['CodigoTipoCarga']);

        $tankCiot = Ciot::factory()->tankAlcohol()->create();

        $tankPayload = app(BuildCiotDeclarationPayload::class)->handle($tankCiot);

        $this->assertSame(1, $tankPayload['DadosCarga']['CodigoNaturezaCarga']);
        $this->assertSame(2, $tankPayload['DadosCarga']['CodigoTipoCarga']);
    }

    public function test_fractioned_operation_includes_additional_payers(): void
    {
        $ciot = Ciot::factory()->create([
            'operation_type' => CiotOperationTypeEnum::Fractioned,
            'payer_cnpj' => '14517191000925',
            'additional_payers' => ['14517191000410', '14517191000259'],
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
        ]);

        $payload = app(BuildCiotDeclarationPayload::class)->handle($ciot);

        $this->assertSame(2, $payload['TipoOperacao']);
        // Nome com o typo da ANTT (sem o "a") e elementos string — List<string>
        // no DTO deles; a grafia correta não liga e objetos crasham (NRE 500).
        $this->assertSame(['14517191000410', '14517191000259'], $payload['DadosCarga']['ContratantesCargFrac']);
        $this->assertArrayNotHasKey('ContratantesCargaFrac', $payload['DadosCarga']);
        // Array vazio de indicadores derruba o transformer da ANTT (NRE, sonda
        // 25/09/2026): para fracionada a chave precisa sair do payload.
        $this->assertArrayNotHasKey('InfIndicadoresOperacionais', $payload);
    }

    public function test_rejects_fractioned_without_additional_payers(): void
    {
        $ciot = Ciot::factory()->create([
            'operation_type' => CiotOperationTypeEnum::Fractioned,
            'additional_payers' => [],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fracionada');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_additional_payers_equal_to_the_payer_b119(): void
    {
        $ciot = Ciot::factory()->create([
            'operation_type' => CiotOperationTypeEnum::Fractioned,
            'payer_cnpj' => '14517191000925',
            'additional_payers' => ['14517191000925'],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('B119');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_composition_without_automotor_b83(): void
    {
        $ciot = Ciot::factory()->create([
            'vehicles' => [
                ['placa' => 'TIX7D32', 'rntrc' => '045963122', 'eixos' => 2, 'tipo' => 'reboque'],
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('B83');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_composition_with_two_automotors_b83(): void
    {
        $ciot = Ciot::factory()->create([
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
                ['placa' => 'ABC1234', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('B83');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_missing_ibge_on_origin_or_destination(): void
    {
        $ciot = Ciot::factory()->create([
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => null],
            'vehicles' => [
                ['placa' => 'PUC8E55', 'rntrc' => '045963122', 'eixos' => 3, 'tipo' => 'automotor'],
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('IBGE');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_missing_bank_configuration(): void
    {
        config(['ciot.lines.vehicle_removal.bank_account' => null]);

        $ciot = Ciot::factory()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CIOT_VEHICLE_REMOVAL_BANK_*');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_tank_line_cannot_be_fractioned(): void
    {
        $ciot = Ciot::factory()->tankAlcohol()->create([
            'operation_type' => CiotOperationTypeEnum::Fractioned,
            'additional_payers' => ['14517191000410'],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('lotação');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_travel_start_in_the_past(): void
    {
        $ciot = Ciot::factory()->create([
            'travel_start_at' => now()->subDay(),
            'travel_end_at' => now()->addDay(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('passado');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }

    public function test_rejects_travel_longer_than_ninety_days(): void
    {
        $ciot = Ciot::factory()->create([
            'travel_start_at' => now(),
            'travel_end_at' => now()->addDays(91),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('90 dias');

        app(BuildCiotDeclarationPayload::class)->handle($ciot);
    }
}
