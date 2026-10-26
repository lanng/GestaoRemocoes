<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDriver;
use App\Services\Cte\ResolveCteSnapshot;
use App\Services\Dfe\BuildMdfeInsuranceSnapshot;
use DomainException;
use Illuminate\Support\Collection;

class BuildMdfeEmissionSnapshot
{
    private const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function __construct(
        private readonly ResolveCteSnapshot $cteSnapshot,
        private readonly BuildMdfeInsuranceSnapshot $insuranceSnapshot,
        private readonly ValidateMdfeDocumentSelection $selectionValidator,
    ) {}

    /**
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     * @return array<string, mixed>
     */
    public function handle(
        CteEmissionBatch $batch,
        array $endorsements,
        array $routeUfs,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): array {
        $ciot = $batch->ciots()
            ->where('status', CiotStatusEnum::ISSUED)
            ->latest('id')
            ->get()
            ->first(fn (Ciot $candidate): bool => ! $candidate->isSyntheticHomologation());

        if (! $ciot instanceof Ciot) {
            throw new DomainException('CIOT emitido não encontrado para este lote.');
        }

        $documents = $batch->documents()
            ->where('status', 'authorized')
            ->whereNotNull('access_key')
            ->get();

        return $this->handleForDocuments(
            documents: $documents,
            ciot: $ciot,
            endorsements: $endorsements,
            routeUfs: $routeUfs,
            batch: $batch,
            driver: $driver,
            homologationFixture: $homologationFixture,
            paymentMethod: $paymentMethod,
            paymentBankCode: $paymentBankCode,
            paymentBankAgency: $paymentBankAgency,
        );
    }

    /**
     * @param  Collection<int, CteDocument>  $documents
     * @param  array<int, string>  $endorsements
     * @param  array<int, string>  $routeUfs
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     * @return array<string, mixed>
     */
    public function handleForDocuments(
        Collection $documents,
        Ciot $ciot,
        array $endorsements,
        array $routeUfs,
        ?CteEmissionBatch $batch = null,
        ?MdfeDriver $driver = null,
        ?array $homologationFixture = null,
        ?string $paymentMethod = null,
        ?string $paymentBankCode = null,
        ?string $paymentBankAgency = null,
    ): array {
        $line = $this->selectionValidator->handle($documents, $ciot);
        $ciotNumber = (string) $ciot->ciot_number;

        if (! preg_match('/^\d{12}$/', (string) $ciotNumber)) {
            throw new DomainException('CIOT emitido ausente ou inválido; o MDF-e exige os 12 dígitos reais.');
        }

        $cteSnapshots = $documents->map(fn (CteDocument $document): array => $this->cteSnapshot->handle($document));
        $origin = $this->city((array) $ciot->origin, 'origem do CIOT');
        $destination = $this->city((array) $ciot->destination, 'destino do CIOT');
        $infLotacao = $this->lotationSnapshot($documents->count(), $origin['cep'], $destination['cep']);
        $routeUfs = $this->normalizeRoute($routeUfs, $origin['uf'], $destination['uf']);
        $vehicle = $this->vehicleSnapshot($ciot, $driver);
        $destinations = $this->destinations($documents, $cteSnapshots);
        $loadingCities = $this->loadingCities($origin);
        $insurance = $this->insuranceSnapshot->handle($endorsements, $homologationFixture);
        $cargoValueCents = $line === CiotLineEnum::TankAlcohol
            ? $this->decimalToCents($cteSnapshots->first()['cargo_value'] ?? null)
            : $documents->sum(fn (CteDocument $document): int => $this->decimalToCents($document->snapshot['fipe_value'] ?? null));
        $contractValueCents = $documents->sum(fn (CteDocument $document): int => $this->decimalToCents($document->snapshot['value'] ?? null));
        $cargoWeightKg = $line === CiotLineEnum::TankAlcohol
            ? (float) (int) ($cteSnapshots->first()['cargo_weight_kg'] ?? 0)
            : (float) $ciot->cargo_weight_kg;

        if ($line === CiotLineEnum::TankAlcohol && $cargoValueCents <= 0) {
            throw new DomainException('O CT-e de álcool precisa informar o valor real da carga no snapshot.');
        }

        if ($cargoWeightKg <= 0) {
            throw new DomainException('O CIOT ou o CT-e selecionado precisa informar o peso real da carga.');
        }

        $cargoType = $this->cargoType($line);
        $ncm = match ($line) {
            CiotLineEnum::VehicleRemoval => (string) config('dfe.mdfe.ncm_vehicle_removal'),
            CiotLineEnum::TankAlcohol => (string) config('dfe.mdfe.ncm_tank_alcohol'),
        };
        $ncm = preg_replace('/\D/', '', $ncm);

        return [
            'schema_version' => 1,
            'trip_id' => $batch?->id,
            'line' => $line->value,
            'issue_at' => now()->toIso8601String(),
            'tp_emit' => (string) config('dfe.mdfe.tp_emit'),
            'ciot' => (string) $ciotNumber,
            'ciot_source' => 'issued',
            'ciot_responsible_cnpj' => preg_replace('/\D/', '', (string) config('dfe.emit.cnpj')),
            'rntrc' => (string) config('dfe.mdfe.rntrc'),
            'origin' => $origin,
            'destination' => $destination,
            'loading_cities' => $loadingCities,
            'route_ufs' => $routeUfs,
            'destinations' => $destinations,
            'vehicle' => $vehicle,
            'inf_lotacao' => $infLotacao,
            'payment' => $this->paymentSnapshot(
                $ciot,
                $contractValueCents,
                $paymentMethod,
                $paymentBankCode,
                $paymentBankAgency,
            ),
            'cargo' => [
                'value' => $this->decimal($cargoValueCents / 100),
                'weight_kg' => $this->decimal($cargoWeightKg, 4),
                'predominant_product' => match ($line) {
                    CiotLineEnum::VehicleRemoval => 'VEICULOS',
                    CiotLineEnum::TankAlcohol => 'ALCOOL ETILICO',
                },
                'type' => $cargoType,
                'ncm' => $ncm,
            ],
            'insurance' => $insurance,
        ];
    }

    /** @return array<int, array{ibge: string, name: string}> */
    public function loadingCitiesFromCiotOrigin(Ciot $ciot): array
    {
        return $this->loadingCities($this->city((array) $ciot->origin, 'origem do CIOT'));
    }

    /** @return array{loading_cep: string, unloading_cep: string}|null */
    private function lotationSnapshot(int $cteCount, ?string $loadingCep, ?string $unloadingCep): ?array
    {
        if ($cteCount !== 1 || (string) config('dfe.mdfe.tp_emit') !== '1') {
            return null;
        }

        $loadingCep = preg_replace('/[\s.-]/', '', trim((string) $loadingCep));
        $unloadingCep = preg_replace('/[\s.-]/', '', trim((string) $unloadingCep));

        if (! preg_match('/^\d{8}$/', (string) $loadingCep) || ! preg_match('/^\d{8}$/', (string) $unloadingCep)) {
            throw new DomainException('Informe o CEP de oito dígitos dos locais efetivos de carregamento e descarregamento do MDF-e de um único CT-e.');
        }

        return [
            'loading_cep' => (string) $loadingCep,
            'unloading_cep' => (string) $unloadingCep,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentSnapshot(
        Ciot $ciot,
        int $contractValueCents,
        ?string $paymentMethod,
        ?string $paymentBankCode,
        ?string $paymentBankAgency,
    ): array {
        $line = $ciot->line instanceof CiotLineEnum ? $ciot->line : CiotLineEnum::from((string) $ciot->line);
        $lineConfig = match ($line) {
            CiotLineEnum::VehicleRemoval => 'ciot.lines.vehicle_removal',
            CiotLineEnum::TankAlcohol => 'ciot.lines.tank_alcohol',
        };
        $bankCode = trim((string) (filled($paymentBankCode) ? $paymentBankCode : config($lineConfig.'.bank_code')));
        $bankAgency = trim((string) (filled($paymentBankAgency) ? $paymentBankAgency : config($lineConfig.'.bank_agency')));
        $payerCnpj = preg_replace('/\D/', '', (string) $ciot->payer_cnpj);
        $payerName = trim((string) $ciot->payer_name);
        $paymentMethod ??= '0';
        $paymentValueCents = (int) $ciot->freight_value_cents > 0
            ? (int) $ciot->freight_value_cents
            : $contractValueCents;

        if ($paymentMethod !== '0') {
            throw new DomainException('O MDF-e aceita pagamento à vista neste fluxo; implemente o pagamento a prazo antes de selecionar outra forma.');
        }

        if (! preg_match('/^\d{3,5}$/', $bankCode) || ! preg_match('/^\d{1,10}$/', $bankAgency)) {
            throw new DomainException('Configure o código do banco e a agência do CIOT para o pagamento do MDF-e.');
        }

        if (mb_strlen($payerName) < 2 || ! preg_match('/^\d{14}$/', (string) $payerCnpj)) {
            throw new DomainException('O CIOT precisa identificar o CNPJ e a razão social da empresa pagante para o MDF-e.');
        }

        if ($paymentValueCents <= 0) {
            throw new DomainException('O CIOT ou os CT-es autorizados precisam informar valor de frete maior que zero para o pagamento do MDF-e.');
        }

        $contractValue = $this->decimal($paymentValueCents / 100);

        return [
            'payer_name' => $payerName,
            'payer_cnpj' => (string) $payerCnpj,
            'components' => [['type' => '04', 'value' => $contractValue]],
            'contract_value' => $contractValue,
            'method' => $paymentMethod,
            'bank_code' => $bankCode,
            'bank_agency' => $bankAgency,
        ];
    }

    /** @param array<string, mixed> $city @return array{ibge: string, name: string, uf: string, cep: string} */
    private function city(array $city, string $label): array
    {
        $ibge = preg_replace('/\D/', '', (string) ($city['ibge'] ?? $city['cMun'] ?? ''));
        $name = trim((string) ($city['cidade'] ?? $city['name'] ?? $city['xMun'] ?? ''));
        $uf = strtoupper(trim((string) ($city['uf'] ?? '')));

        if (strlen((string) $ibge) !== 7 || $name === '' || ! in_array($uf, self::UFS, true)) {
            throw new DomainException("Dados incompletos para {$label}: são necessários município IBGE, nome e UF cadastrados.");
        }

        $cep = preg_replace('/[\s.-]/', '', trim((string) ($city['cep'] ?? '')));

        return ['ibge' => (string) $ibge, 'name' => $name, 'uf' => $uf, 'cep' => (string) $cep];
    }

    /** @param array<int, string> $routeUfs @return array<int, string> */
    private function normalizeRoute(array $routeUfs, string $originUf, string $destinationUf): array
    {
        $normalized = [];

        foreach ($routeUfs as $uf) {
            $uf = strtoupper(trim($uf));

            if (! in_array($uf, self::UFS, true) || $uf === $originUf || $uf === $destinationUf || in_array($uf, $normalized, true)) {
                throw new DomainException("UF intermediária inválida ou repetida no percurso do MDF-e: {$uf}.");
            }

            $normalized[] = $uf;
        }

        if ($originUf !== $destinationUf && $normalized === []) {
            throw new DomainException('Informe manualmente as UFs intermediárias do percurso interestadual do MDF-e.');
        }

        return $normalized;
    }

    private function cargoType(CiotLineEnum $line): string
    {
        return match ($line) {
            CiotLineEnum::VehicleRemoval => '05',
            CiotLineEnum::TankAlcohol => '08',
        };
    }

    /** @return array<string, mixed> */
    private function vehicleSnapshot(Ciot $ciot, ?MdfeDriver $selectedDriver): array
    {
        $line = $ciot->line instanceof CiotLineEnum ? $ciot->line->value : (string) $ciot->line;
        $ciotVehicles = (array) $ciot->vehicles;
        $plates = collect($ciotVehicles)->map(fn (array $row): string => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) ($row['placa'] ?? $row['plate'] ?? ''))))->filter()->unique()->values();
        $catalogVehicles = CiotVehicle::query()->whereIn('plate', $plates)->where('is_active', true)->get()->keyBy('plate');

        if ($plates->count() === 0 || $catalogVehicles->count() !== $plates->count()) {
            throw new DomainException('Todos os veículos do CIOT devem estar cadastrados e ativos no cadastro de frota do MDF-e.');
        }

        $automotors = $catalogVehicles->filter(fn (CiotVehicle $vehicle): bool => $vehicle->type === CiotVehicle::TYPE_AUTOMOTOR);

        if ($automotors->count() !== 1) {
            throw new DomainException('O CIOT deve corresponder a exatamente um veículo trator cadastrado para o MDF-e.');
        }

        $tractor = $automotors->first();
        if (! $tractor->servesLine($line)) {
            throw new DomainException('O veículo trator selecionado não está habilitado para esta linha de emissão.');
        }

        foreach (['tare_kg' => 'tara', 'type_rod' => 'tipo de rodado', 'body_type' => 'tipo de carroceria', 'uf' => 'UF do veículo'] as $field => $label) {
            if (blank($tractor->{$field})) {
                throw new DomainException("Complete {$label} do veículo {$tractor->plate} no cadastro de frota antes de emitir o MDF-e.");
            }
        }

        if ($line === CiotLineEnum::VehicleRemoval->value) {
            $driver = MdfeDriver::query()->where('line', $line)->where('is_active', true)->where('is_default', true)->first();
        } else {
            $driver = $selectedDriver;
        }

        if (! $driver instanceof MdfeDriver || ! $driver->is_active || $driver->line !== $line) {
            throw new DomainException($line === CiotLineEnum::VehicleRemoval->value
                ? 'Cadastre um motorista ativo padrão para a linha de remoção de veículos.'
                : 'Selecione um motorista ativo cadastrado para a linha de tanque de álcool.');
        }

        $trailers = $catalogVehicles->reject(fn (CiotVehicle $vehicle): bool => $vehicle->isAutomotor())
            ->map(fn (CiotVehicle $trailer): array => $trailer->mdfeSnapshot())->values()->all();

        return array_merge($tractor->mdfeSnapshot(), [
            'drivers' => [['name' => $driver->name, 'cpf' => preg_replace('/\D/', '', $driver->cpf)]],
            'trailers' => $trailers,
        ]);
    }

    /** @param Collection<int, CteDocument> $documents @param Collection<int, array<string, mixed>> $snapshots @return array<int, array<string, mixed>> */
    private function destinations(Collection $documents, Collection $snapshots): array
    {
        $groups = [];

        foreach ($snapshots->values() as $index => $snapshot) {
            $destination = $this->city((array) ($snapshot['destination'] ?? []), 'destino do CT-e');
            $key = $destination['ibge'];
            $groups[$key] ??= $destination + ['cte_access_keys' => []];
            $groups[$key]['cte_access_keys'][] = (string) $documents->values()[$index]->access_key;
        }

        return array_values($groups);
    }

    /** @param array{ibge: string, name: string, uf: string} $origin @return array<int, array{ibge: string, name: string}> */
    private function loadingCities(array $origin): array
    {
        return [['ibge' => $origin['ibge'], 'name' => $origin['name']]];
    }

    private function decimalToCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function decimal(float $value, int $precision = 2): string
    {
        return number_format($value, $precision, '.', '');
    }
}
