<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Models\CiotVehicle;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Services\Cte\ExactDecimal;
use DomainException;
use Illuminate\Support\Collection;

class PrefillCiotFromDocuments
{
    /**
     * @param  Collection<int, CteDocument>  $documents
     * @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection}
     */
    public function handle(Collection $documents, ?CteEmissionBatch $batch = null): array
    {
        if ($documents->contains(fn (mixed $document): bool => ! $document instanceof CteDocument)) {
            throw new DomainException('Selecione ao menos um CT-e interno para preparar o CIOT.');
        }

        if ($documents->isEmpty()) {
            if ($batch === null) {
                throw new DomainException('Selecione ao menos um CT-e interno para preparar o CIOT.');
            }

            return $this->prefillVehicleRemoval($documents);
        }

        $lines = $documents
            ->map(fn (CteDocument $document): CiotLineEnum => $this->documentLine($document))
            ->unique()
            ->values();

        if ($lines->count() !== 1) {
            throw new DomainException('A preparação do CIOT não pode misturar CT-es de modalidades diferentes.');
        }

        $line = $lines->first();

        if (in_array($line, [CiotLineEnum::TankAlcohol, CiotLineEnum::GeneralCargo], true) && $documents->count() !== 1) {
            throw new DomainException($line === CiotLineEnum::GeneralCargo
                ? 'A preparação avulsa de carga geral aceita exatamente um CT-e.'
                : 'A preparação de álcool aceita exatamente um CT-e.');
        }

        return match ($line) {
            CiotLineEnum::TankAlcohol => $this->prefillTankAlcohol($documents->first()),
            CiotLineEnum::GeneralCargo => $this->prefillGeneralCargo($documents->first()),
            CiotLineEnum::VehicleRemoval => $this->prefillVehicleRemoval($documents),
        };
    }

    /**
     * @return Collection<int, CiotVehicle>
     */
    public function lineVehicles(CiotLineEnum $line = CiotLineEnum::VehicleRemoval): Collection
    {
        return CiotVehicle::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('line', $line->operationalProfile()->value)
                ->orWhereNull('line'))
            ->orderBy('type')
            ->orderBy('plate')
            ->get();
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection}
     */
    private function prefillVehicleRemoval(Collection $documents): array
    {
        $warnings = [];
        $origins = $this->resolveSnapshotCities($documents, 'origin', $warnings);
        $patios = $this->resolveDestinationPatios($documents);
        $pairs = $this->distancePairs($origins, $patios, $documents);

        usort($pairs, fn (array $first, array $second): int => $second['km'] <=> $first['km']);
        $best = $pairs[0] ?? null;
        $ranking = collect($pairs)
            ->map(fn (array $pair): string => sprintf('%s → %s: %d km', $pair['origin']->name, $pair['payer']->name, $pair['km']))
            ->implode("\n");

        if ($patios->isEmpty()) {
            $warnings[] = 'Nenhum pátio do cadastro corresponde às cidades de destino dos CT-es — selecione pagantes e destinatário manualmente.';
        } elseif ($best === null) {
            $warnings[] = 'Não foi possível calcular uma rota com coordenadas cadastradas — informe a distância manualmente.';
        }

        $weightPerVehicle = max(1, (int) config('ciot.removal.weight_per_vehicle_kg'));
        $form = [
            'line' => CiotLineEnum::VehicleRemoval->value,
            'operation_type' => $patios->count() > 1 ? 'fractioned' : 'lotation',
            'payer_id' => $best['payer']->id ?? null,
            'delivery_payer_id' => $best['payer']->id ?? null,
            'additional_payers' => $patios
                ->reject(fn (Company $patio): bool => $patio->id === ($best['payer']->id ?? null))
                ->pluck('cnpj')
                ->values()
                ->all(),
            'origin.ibge' => $best['origin']->ibge_code ?? null,
            'origin.cidade' => $best['origin']->name ?? null,
            'origin.uf' => $best['origin']->state ?? null,
            'destination' => $best['destination'] ?? null,
            'distance_km' => $best['km'] ?? null,
            'freight_value' => number_format($this->totalInCents($documents, 'value') / 100, 2, '.', ''),
            'cargo_weight_kg' => $documents->count() * $weightPerVehicle,
            'vehicle_ids' => [],
            'travel_start_at' => today(),
            'travel_end_at' => today()->addDay(),
        ];

        return [
            'form' => $form,
            'ranking' => $ranking,
            'warnings' => $warnings,
            'origins' => $origins,
            'patios' => $patios,
        ];
    }

    /**
     * @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection}
     */
    private function prefillTankAlcohol(CteDocument $document): array
    {
        $warnings = [];
        $origin = $this->place($document, 'origin');
        $destination = $this->place($document, 'destination');
        $deliveryParty = (array) ($document->snapshot['delivery_party'] ?? []);
        $fiscalRecipient = (array) ($document->snapshot['dest'] ?? []);
        $hasSeparateDeliveryParty = collect($deliveryParty)->contains(fn (mixed $value): bool => filled($value));

        if (! $hasSeparateDeliveryParty) {
            $deliveryParty = $fiscalRecipient;
        }

        $payer = $this->companyByCnpj((string) ($fiscalRecipient['cnpj'] ?? ''));
        $delivery = $this->companyByCnpj((string) ($deliveryParty['cnpj'] ?? ''));
        $cities = City::query()->orderBy('name')->get();
        $originCity = $this->resolveCityForPlace($origin, $warnings, 'origem', $cities);
        $origins = collect($originCity === null ? [] : [$originCity]);
        $destinationCity = $this->resolveCityForPlace($destination, $warnings, 'entrega efetiva', $cities);
        $distance = null;

        if ($originCity !== null && $destinationCity !== null) {
            $distance = app(CityDistanceCalculator::class)->calculate($originCity, $destinationCity);
        }

        if ($distance === null) {
            $warnings[] = 'Não foi possível calcular a rota com coordenadas cadastradas — informe a distância manualmente.';
        }

        if ($payer === null) {
            $warnings[] = 'O destinatário fiscal do CT-e não está no cadastro de empresas — confirme ou cadastre o pagante antes de criar o CIOT.';
        }

        if ($delivery === null) {
            $warnings[] = 'A entrega efetiva não está no cadastro de empresas — confirme ou cadastre o destinatário da entrega antes de criar o CIOT.';
        }

        $cargoWeight = $this->decimalString($document->snapshot['cargo_weight_kg'] ?? null, 0);

        if ($cargoWeight === '') {
            $warnings[] = 'O CT-e não informa o peso real da carga — complete o peso antes de emitir o CIOT.';
        }

        $cargoValue = $this->decimalString($document->snapshot['cargo_value'] ?? null, 2);

        if ($cargoValue === '' || $this->decimalToCents($cargoValue) <= 0) {
            $warnings[] = 'O CT-e não informa o valor real da carga — corrija os dados antes de emitir o MDF-e.';
        }

        $freightValue = $this->decimalString($document->snapshot['value'] ?? null, 2);

        if ($freightValue === '' || $this->decimalToCents($freightValue) <= 0) {
            $warnings[] = 'O CT-e não informa um frete positivo — complete o valor antes de emitir o CIOT.';
        }

        $travelEnd = $this->dateValue($document->snapshot['dprev'] ?? null);

        if ($travelEnd === null) {
            $travelEnd = today()->addDay();
        }

        return [
            'form' => [
                'line' => CiotLineEnum::TankAlcohol->value,
                'operation_type' => 'lotation',
                'payer_id' => $payer?->id,
                'payer_cnpj' => preg_replace('/\D/', '', (string) ($fiscalRecipient['cnpj'] ?? '')),
                'payer_name' => (string) ($fiscalRecipient['xNome'] ?? $fiscalRecipient['name'] ?? ''),
                'delivery_payer_id' => $delivery?->id,
                'delivery_payer_cnpj' => preg_replace('/\D/', '', (string) ($deliveryParty['cnpj'] ?? '')),
                'delivery_payer_name' => (string) ($deliveryParty['xNome'] ?? $deliveryParty['name'] ?? ''),
                'additional_payers' => [],
                'origin.ibge' => $originCity?->ibge_code ?? ($origin['ibge'] ?: null),
                'origin.cidade' => $originCity?->name ?? $origin['name'],
                'origin.uf' => $originCity?->state ?? $origin['uf'],
                'origin.cep' => $origin['cep'],
                'destination' => [
                    'cidade' => $destination['name'],
                    'uf' => $destination['uf'],
                    'cep' => $destination['cep'],
                    'ibge' => $destination['ibge'],
                ],
                'distance_km' => $distance,
                'freight_value' => $freightValue,
                'cargo_weight_kg' => $cargoWeight,
                'vehicle_ids' => [],
                'travel_start_at' => today(),
                'travel_end_at' => $travelEnd,
            ],
            'ranking' => $distance === null ? '' : sprintf('%s → %s: %d km', $originCity->name, $destinationCity->name, $distance),
            'warnings' => $warnings,
            'origins' => $origins,
            'patios' => collect([$payer, $delivery])->filter()->unique('id')->values(),
        ];
    }

    /** @return array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: Collection, patios: Collection} */
    private function prefillGeneralCargo(CteDocument $document): array
    {
        $snapshot = (array) $document->snapshot;
        $warnings = [];
        $origin = $this->place($document, 'origin');
        $destination = $this->place($document, 'destination');
        $cities = City::query()->orderBy('name')->get();
        $originCity = $this->resolveCityForPlace($origin, $warnings, 'origem', $cities);
        $destinationCity = $this->resolveCityForPlace($destination, $warnings, 'destino', $cities);
        $distance = $originCity !== null && $destinationCity !== null
            ? app(CityDistanceCalculator::class)->calculate($originCity, $destinationCity)
            : null;

        if ($distance === null) {
            $warnings[] = 'Não foi possível calcular a rota entre os municípios do CT-e — informe a distância manualmente.';
        }

        $payerPartyKey = match ((string) ($snapshot['taker_code'] ?? '')) {
            '0' => 'rem',
            '3' => 'dest',
            '4' => 'taker',
            default => null,
        };
        $payerParty = $payerPartyKey === null ? [] : (array) ($snapshot[$payerPartyKey] ?? []);
        $payerCnpj = preg_replace('/\D/', '', (string) ($payerParty['cnpj'] ?? '')) ?: '';
        $payer = $this->companyByCnpj($payerCnpj);

        if ($payer === null) {
            $warnings[] = 'O tomador do CT-e não corresponde a uma empresa ativa com CNPJ cadastrado — selecione o contratante manualmente.';
        }

        $deliveryParty = (array) ($snapshot['dest'] ?? []);
        $deliveryCnpj = preg_replace('/\D/', '', (string) ($deliveryParty['cnpj'] ?? '')) ?: '';
        $delivery = $this->companyByCnpj($deliveryCnpj);

        if ($delivery === null) {
            $warnings[] = 'O destinatário do CT-e não corresponde a uma empresa ativa com CNPJ cadastrado — selecione o destinatário da entrega manualmente.';
        }

        $weight = data_get($snapshot, 'cargo.weight_kg');

        if (blank($weight)) {
            $weight = $snapshot['cargo_weight_kg'] ?? null;
        }

        $cargoWeight = '';

        if (filled($weight)) {
            try {
                $weightCentiKg = ExactDecimal::parse((string) $weight, 2, 'CT-e avulso', 'peso da carga');
            } catch (DomainException $exception) {
                throw new DomainException('O CT-e avulso deve informar o peso real da carga com até duas casas decimais.', previous: $exception);
            }

            if ($weightCentiKg > 0) {
                $cargoWeight = ExactDecimal::format($weightCentiKg, 2);
            }
        }

        if ($cargoWeight === '') {
            $warnings[] = 'O CT-e não informa o peso real da carga — complete o peso manualmente antes de emitir o CIOT.';
        }

        $freightValue = $this->decimalString(
            filled(data_get($snapshot, 'freight.total')) ? data_get($snapshot, 'freight.total') : ($snapshot['value'] ?? null),
            2,
        );

        if ($freightValue === '' || $this->decimalToCents($freightValue) <= 0) {
            $warnings[] = 'O CT-e não informa um frete positivo — complete o valor antes de emitir o CIOT.';
        }

        $form = [
            'line' => CiotLineEnum::GeneralCargo->value,
            'operation_type' => 'lotation',
            'payer_id' => $payer?->id,
            'payer_cnpj' => $payerCnpj,
            'payer_name' => (string) ($payerParty['xNome'] ?? $payerParty['name'] ?? ''),
            'delivery_payer_id' => $delivery?->id,
            'delivery_payer_cnpj' => $deliveryCnpj,
            'delivery_payer_name' => (string) ($deliveryParty['xNome'] ?? $deliveryParty['name'] ?? ''),
            'additional_payers' => [],
            'origin.ibge' => $origin['ibge'] ?: null,
            'origin.cidade' => $origin['name'],
            'origin.uf' => $origin['uf'],
            'origin.cep' => $origin['cep'] ?: null,
            'destination' => [
                'cidade' => $destination['name'],
                'uf' => $destination['uf'],
                'cep' => $destination['cep'] ?: null,
                'ibge' => $destination['ibge'] ?: null,
            ],
            'distance_km' => $distance,
            'freight_value' => $freightValue,
            'cargo_weight_kg' => $cargoWeight,
            'vehicle_ids' => [],
            'travel_start_at' => today(),
            'travel_end_at' => $this->dateValue($snapshot['dprev'] ?? null) ?? today()->addDay(),
        ];

        return [
            'form' => $form,
            'ranking' => $distance === null ? '' : sprintf('%s → %s: %d km', $origin['name'], $destination['name'], $distance),
            'warnings' => $warnings,
            'origins' => collect($originCity === null ? [] : [$originCity]),
            'patios' => collect([$payer, $delivery])->filter()->unique('id')->values(),
        ];
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  list<string>  $warnings
     * @return Collection<int, City>
     */
    private function resolveSnapshotCities(Collection $documents, string $role, array &$warnings): Collection
    {
        $places = $documents
            ->map(fn (CteDocument $document): array => $this->place($document, $role))
            ->unique(fn (array $place): string => $place['ibge'] ?: $this->normalize($place['name']));
        $cities = collect();
        $allCities = City::query()->orderBy('name')->get();

        foreach ($places as $place) {
            $city = $this->resolveCityForPlace($place, $warnings, $role, $allCities);

            if ($city !== null) {
                $cities->push($city);
            }
        }

        return $cities->unique('ibge_code')->values();
    }

    /**
     * @param  array{ibge: string, name: string, uf: string, cep: string}  $place
     * @param  list<string>  $warnings
     * @param  Collection<int, City>|null  $cities
     */
    private function resolveCityForPlace(array $place, array &$warnings, string $role, ?Collection $cities = null): ?City
    {
        $cities ??= City::query()->orderBy('name')->get();
        $city = filled($place['ibge'])
            ? $cities->first(fn (City $candidate): bool => $candidate->ibge_code === $place['ibge'])
            : null;

        if ($city === null) {
            $city = $this->matchCity($cities, $place['name'], $place['uf']);
        }

        if ($city === null) {
            $label = $place['name'] !== '' ? $place['name'] : $place['ibge'];
            $warnings[] = "Cidade de {$role} '{$label}' não encontrada na base IBGE — informe manualmente.";
        }

        return $city;
    }

    /**
     * @param  Collection<int, City>  $origins
     * @param  Collection<int, Company>  $patios
     * @param  Collection<int, CteDocument>  $documents
     * @return list<array{origin: City, payer: Company, km: int, destination: array<string, mixed>}>
     */
    private function distancePairs(Collection $origins, Collection $patios, Collection $documents): array
    {
        $pairs = [];

        foreach ($origins as $origin) {
            foreach ($patios as $patio) {
                foreach ($this->destinationLocations($documents, $patio) as $destination) {
                    $destinationCity = filled($destination['ibge'])
                        ? City::query()->where('ibge_code', $destination['ibge'])->first()
                        : null;

                    if ($destinationCity === null || ! $origin->hasCoordinates() || ! $destinationCity->hasCoordinates()) {
                        continue;
                    }

                    $km = app(CityDistanceCalculator::class)->calculate($origin, $destinationCity);

                    if ($km !== null) {
                        $pairs[] = ['origin' => $origin, 'payer' => $patio, 'km' => $km, 'destination' => $destination];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @return Collection<int, Company>
     */
    private function resolveDestinationPatios(Collection $documents): Collection
    {
        $names = $documents
            ->filter(fn (CteDocument $document): bool => blank($document->snapshot['dest']['cnpj'] ?? null))
            ->map(fn (CteDocument $document): string => $this->place($document, 'destination')['name'])
            ->filter()->unique();
        $cnpjs = $documents
            ->map(fn (CteDocument $document): string => (string) (((array) ($document->snapshot['dest'] ?? []))['cnpj'] ?? ''))
            ->filter()
            ->map(fn (string $cnpj): string => preg_replace('/\D/', '', $cnpj) ?? '')
            ->unique();

        return Company::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Company $company): bool => $cnpjs->contains(preg_replace('/\D/', '', (string) $company->cnpj)
                ?: '')
                || $names->contains(fn (string $name): bool => strcasecmp(trim($name), trim((string) $company->city)) === 0))
            ->values();
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @return list<array{cidade: string, uf: string, ibge: ?string, cep: ?string}>
     */
    private function destinationLocations(Collection $documents, Company $payer): array
    {
        $locations = $documents
            ->filter(fn (CteDocument $document): bool => Company::normalizeCnpj($document->snapshot['dest']['cnpj'] ?? null) === $payer->cnpj)
            ->map(function (CteDocument $document) use ($payer): array {
                $place = $this->place($document, 'destination');
                $ibge = $place['ibge'] ?: $payer->ibge_code;

                return [
                    'cidade' => $place['name'] ?: $payer->city,
                    'uf' => $place['uf'] ?: $payer->state,
                    'ibge' => $ibge,
                    'cep' => $place['cep'] ?: ($ibge === $payer->ibge_code ? $payer->zipcode : null),
                ];
            })->unique(fn (array $place): string => $place['ibge'].'|'.$place['cidade'].'|'.$place['uf'])->values();

        return $locations->isEmpty() ? [$payer->location()] : $locations->all();
    }

    private function companyByCnpj(string $cnpj): ?Company
    {
        $cnpj = preg_replace('/\D/', '', $cnpj) ?? '';

        return $cnpj === ''
            ? null
            : Company::query()
                ->where('is_active', true)
                ->get()
                ->first(fn (Company $company): bool => preg_replace('/\D/', '', (string) $company->cnpj) === $cnpj);
    }

    /** @param  Collection<int, City>  $cities */
    private function matchCity(Collection $cities, string $name, string $uf = ''): ?City
    {
        $target = $this->normalize($name);
        $matches = $cities->filter(fn (City $city): bool => $this->normalize($city->name) === $target);

        if ($uf !== '') {
            $sameState = $matches->first(fn (City $city): bool => strtoupper($city->state) === strtoupper($uf));

            if ($sameState !== null) {
                return $sameState;
            }
        }

        if ($matches->count() > 1) {
            $withCoordinates = $matches->filter(fn (City $city): bool => $city->hasCoordinates());

            return ($withCoordinates->isNotEmpty() ? $withCoordinates : $matches)->first();
        }

        return $matches->first();
    }

    /** @return array{ibge: string, name: string, uf: string, cep: string} */
    private function place(CteDocument $document, string $role): array
    {
        $snapshot = (array) $document->snapshot;
        $place = (array) ($snapshot[$role] ?? []);

        if ($role === 'destination' && ($snapshot['line'] ?? null) === CiotLineEnum::TankAlcohol->value) {
            $delivery = (array) ($snapshot['delivery_party'] ?? []);
            $deliveryDestination = [
                'ibge' => $delivery['ibge'] ?? $delivery['cMun'] ?? $delivery['ibge_code'] ?? null,
                'name' => $delivery['name'] ?? $delivery['cidade'] ?? $delivery['xMun'] ?? null,
                'uf' => $delivery['uf'] ?? $delivery['state'] ?? null,
                'cep' => $delivery['cep'] ?? $delivery['zipcode'] ?? null,
            ];
            $place = array_replace(
                $place,
                array_filter($deliveryDestination, fn (mixed $value): bool => filled($value)),
            );
        }

        if ($role === 'origin' && ($snapshot['line'] ?? null) === CiotLineEnum::TankAlcohol->value) {
            $remitter = (array) ($snapshot['rem'] ?? []);

            if (! filled($place['cep'] ?? null)) {
                $place['cep'] = $place['zipcode'] ?? $remitter['cep'] ?? $remitter['zipcode'] ?? null;
            }
        }

        return [
            'ibge' => preg_replace('/\D/', '', (string) ($place['ibge'] ?? $place['cMun'] ?? $place['ibge_code'] ?? '')) ?: '',
            'name' => trim((string) ($place['name'] ?? $place['cidade'] ?? $place['xMun'] ?? $snapshot[$role.'_city'] ?? '')),
            'uf' => strtoupper(trim((string) ($place['uf'] ?? $place['state'] ?? ''))),
            'cep' => preg_replace('/\D/', '', (string) ($place['cep'] ?? $place['zipcode'] ?? '')) ?: '',
        ];
    }

    private function documentLine(CteDocument $document): CiotLineEnum
    {
        $line = CiotLineEnum::tryFrom((string) ($document->snapshot['line'] ?? CiotLineEnum::VehicleRemoval->value));

        if ($line === null) {
            throw new DomainException('O CT-e selecionado não possui uma modalidade de CIOT reconhecida.');
        }

        return $line;
    }

    /** @param  Collection<int, CteDocument>  $documents */
    private function totalInCents(Collection $documents, string $field): int
    {
        return $documents->sum(fn (CteDocument $document): int => $this->decimalToCents($document->snapshot[$field] ?? null));
    }

    private function decimalToCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException('O snapshot do CT-e contém um valor decimal inválido.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function decimalString(mixed $value, int $precision): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = trim((string) $value);

        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException('O snapshot do CT-e contém um valor decimal inválido.');
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $formatted = ltrim($whole, '0') ?: '0';

        if ($precision === 0) {
            return ($negative ? '-' : '').$formatted;
        }

        $fraction = str_pad(substr($fraction, 0, $precision), $precision, '0');

        return ($negative ? '-' : '').$formatted.'.'.$fraction;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $transliterated ?: $value) ?? $value));
    }
}
