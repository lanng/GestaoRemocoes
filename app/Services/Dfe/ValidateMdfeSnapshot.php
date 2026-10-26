<?php

namespace App\Services\Dfe;

use DomainException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ValidateMdfeSnapshot
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /** @var array<string, string> */
    private const UF_CODES = [
        'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
        'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
        'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
        'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
    ];

    /** @param array<string, mixed> $snapshot */
    public function assertLoadingCitiesMatchOrigin(array $snapshot): void
    {
        $originUf = (string) data_get($snapshot, 'origin.uf');
        $expectedPrefix = self::UF_CODES[$originUf] ?? null;

        foreach ((array) ($snapshot['loading_cities'] ?? []) as $city) {
            if (! is_array($city)) {
                throw new DomainException("Um município de carregamento não corresponde à UF de início {$originUf} do MDF-e.");
            }

            $ibge = (string) ($city['ibge'] ?? '');
            $cityName = (string) ($city['name'] ?? 'município sem nome');

            if ($expectedPrefix === null || ! preg_match('/^\d{7}$/', (string) $ibge) || substr((string) $ibge, 0, 2) !== $expectedPrefix) {
                throw new DomainException("O município {$cityName} (IBGE {$ibge}) não corresponde à UF de início {$originUf} do MDF-e.");
            }
        }
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function handle(array $snapshot): array
    {
        foreach (['vehicle', 'insurance', 'payment', 'cargo', 'inf_lotacao'] as $section) {
            if (isset($snapshot[$section]) && is_array($snapshot[$section])) {
                $snapshot[$section] = array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $snapshot[$section]);
            }
        }
        $snapshot['vehicle']['plate'] = $this->plate($snapshot['vehicle']['plate'] ?? '');
        foreach ($snapshot['vehicle']['trailers'] ?? [] as $index => $trailer) {
            $snapshot['vehicle']['trailers'][$index]['plate'] = $this->plate($trailer['plate'] ?? '');
        }
        $rules = [
            'issue_at' => ['required', 'date'],
            'tp_emit' => ['required', Rule::in(['1', '2', '3'])],
            'ciot' => ['required', 'regex:/^\d{12}$/'],
            'ciot_responsible_cnpj' => ['required', 'regex:/^\d{14}$/'],
            'rntrc' => ['required', 'regex:/^\d{8}$/'],
            'route_ufs' => ['present', 'array'],
            'route_ufs.*' => [Rule::in(self::UFS), 'distinct'],
            'loading_cities' => ['required', 'array', 'min:1'],
            'loading_cities.*.ibge' => ['required', 'regex:/^\d{7}$/'],
            'loading_cities.*.name' => ['required', 'string', 'max:60'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.ibge' => ['required', 'regex:/^\d{7}$/'],
            'destinations.*.name' => ['required', 'string', 'max:60'],
            'destinations.*.cte_access_keys' => ['required', 'array', 'min:1'],
            'destinations.*.cte_access_keys.*' => ['required', 'regex:/^\d{44}$/', 'distinct'],
            'vehicle.plate' => ['required', 'regex:/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/'],
            'vehicle.uf' => ['required', Rule::in(self::UFS)],
            'vehicle.renavam' => ['nullable', 'regex:/^\d{9,11}$/'],
            'vehicle.tare_kg' => ['required', 'integer', 'min:1', 'max:999999'],
            'vehicle.capacity_kg' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'vehicle.capacity_m3' => ['nullable', 'integer', 'min:1', 'max:999'],
            'vehicle.type_rod' => ['required', Rule::in(['01', '02', '03', '04', '05', '06'])],
            'vehicle.body_type' => ['required', Rule::in(['00', '01', '02', '03', '04', '05'])],
            'vehicle.drivers' => ['required', 'array', 'min:1'],
            'vehicle.drivers.*.name' => ['required', 'string', 'max:60'],
            'vehicle.drivers.*.cpf' => ['required', 'regex:/^\d{11}$/'],
            'vehicle.trailers' => ['present', 'array'],
            'cargo.type' => ['required', 'regex:/^(0[1-9]|1[0-2])$/'],
            'cargo.predominant_product' => ['required', 'string', 'max:120'],
            'cargo.ncm' => ['nullable', 'regex:/^(\d{2}|\d{8})$/'],
            'cargo.value' => ['required', 'numeric', 'min:0.01'],
            'cargo.weight_kg' => ['required', 'numeric', 'min:0.0001'],
            'insurance.insurer_name' => ['required', 'string', 'max:30'],
            'insurance.insurer_cnpj' => ['required', 'regex:/^\d{14}$/'],
            'insurance.policy_number' => ['required', 'string', 'max:20'],
            'insurance.endorsements' => ['required', 'array', 'min:1'],
            'insurance.endorsements.*' => ['required', 'string', 'max:40'],
            'payment' => ['nullable', 'array'],
            'inf_lotacao' => ['nullable', 'array'],
        ];
        foreach (['origin', 'destination'] as $city) {
            $rules["$city.uf"] = ['required', Rule::in(self::UFS)];
            $rules["$city.ibge"] = ['required', 'regex:/^\d{7}$/'];
            $rules["$city.name"] = ['required', 'string', 'max:60'];
            $rules["$city.cep"] = ['nullable', 'regex:/^\d{8}$/'];
        }
        foreach (['plate', 'renavam', 'tare_kg', 'capacity_kg', 'capacity_m3', 'body_type', 'uf'] as $field) {
            $rules["vehicle.trailers.*.$field"] = $rules["vehicle.$field"];
        }
        if (($snapshot['inf_lotacao'] ?? null) !== null) {
            $rules['inf_lotacao.loading_cep'] = ['required', 'regex:/^\d{8}$/'];
            $rules['inf_lotacao.unloading_cep'] = ['required', 'regex:/^\d{8}$/'];
        }
        if (($snapshot['payment'] ?? null) !== null) {
            $rules += [
                'payment.payer_name' => ['required', 'string', 'max:60'],
                'payment.payer_cnpj' => ['required', 'regex:/^\d{14}$/'],
                'payment.contract_value' => ['required', 'numeric', 'min:0.01'],
                'payment.method' => ['required', Rule::in(['0', '1'])],
                'payment.bank_code' => ['required', 'regex:/^\d{3,5}$/'],
                'payment.bank_agency' => ['required', 'regex:/^\d{1,10}$/'],
                'payment.components' => ['required', 'array', 'min:1'],
                'payment.components.*.type' => ['required', 'regex:/^0[1-5]$/'],
                'payment.components.*.value' => ['required', 'numeric', 'min:0'],
            ];
        }
        Validator::make($snapshot, $rules, [], [
            'vehicle.plate' => 'placa', 'vehicle.drivers.*.cpf' => 'CPF do motorista',
            'rntrc' => 'RNTRC', 'cargo.weight_kg' => 'peso da carga',
        ])->validate();
        $this->assertLoadingCitiesMatchOrigin($snapshot);
        foreach (['value' => 2, 'weight_kg' => 4] as $key => $precision) {
            $snapshot['cargo'][$key] = number_format((float) $snapshot['cargo'][$key], $precision, '.', '');
        }
        if (is_array($snapshot['payment'] ?? null)) {
            $snapshot['payment']['contract_value'] = number_format((float) $snapshot['payment']['contract_value'], 2, '.', '');
            foreach ($snapshot['payment']['components'] as &$component) {
                $component['value'] = number_format((float) $component['value'], 2, '.', '');
            }
            unset($component);
        }

        return $snapshot;
    }

    private function plate(string $value): string
    {
        return strtoupper(str_replace(['-', ' '], '', trim($value)));
    }
}
