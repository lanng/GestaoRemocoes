<?php

namespace App\Services\Dfe;

use Carbon\Carbon;
use DomainException;

class BuildMdfeXml
{
    public function __construct(
        protected AllocateMdfeNumber $allocator,
        protected AccessKey $accessKey,
        protected BuildMdfeInsuranceSnapshot $insuranceSnapshot,
        protected ValidateMdfeSnapshot $snapshotValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(array $snapshot, ?int $number = null, ?int $series = null, ?string $numericCode = null): BuiltMdfe
    {
        $this->assertSnapshotComplete($snapshot);

        $date = Carbon::parse((string) $snapshot['issue_at']);
        $series ??= (int) config('dfe.mdfe.series');
        if ($series < 1 || $series > 999 || ($number !== null && ($number < 1 || $number > 999999999))) {
            throw new DomainException('Número ou série do MDF-e inválidos.');
        }
        $number ??= $this->allocator->handle(series: $series);
        $numericCode ??= str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        if (! preg_match('/^\d{8}$/', $numericCode)) {
            throw new DomainException('Código numérico do MDF-e inválido.');
        }
        $accessKey = $this->accessKey->build(
            cnpj: (string) config('dfe.emit.cnpj'),
            aamm: $date->format('ym'),
            serie: $series,
            number: $number,
            modelo: AccessKey::MODELO_MDFE,
            tpEmis: (string) config('dfe.mdfe.tp_emis'),
            cct: $numericCode,
        );

        return new BuiltMdfe(
            xml: $this->render($snapshot, $accessKey, $number, $series, $numericCode),
            accessKey: $accessKey,
            number: $number,
            series: $series,
            numericCode: $numericCode,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function render(array $snapshot, string $accessKey, int $number, int $series, string $numericCode): string
    {
        $origin = $snapshot['origin'];
        $destination = $snapshot['destination'];
        $routeUfs = $this->routeUfs($snapshot);
        $ctes = array_merge(...array_map(
            fn (array $destinationGroup): array => $destinationGroup['cte_access_keys'],
            $snapshot['destinations'],
        ));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<MDFe xmlns="http://www.portalfiscal.inf.br/mdfe">'
            .'<infMDFe Id="MDFe'.$accessKey.'" versao="'.e((string) config('dfe.mdfe.version')).'">'
            .'<ide>'
            .'<cUF>'.e((string) config('dfe.cuf')).'</cUF>'
            .'<tpAmb>'.e((string) config('dfe.tp_amb')).'</tpAmb>'
            .'<tpEmit>'.e((string) ($snapshot['tp_emit'] ?? config('dfe.mdfe.tp_emit'))).'</tpEmit>'
            .'<mod>58</mod><serie>'.$series.'</serie><nMDF>'.$number.'</nMDF>'
            .'<cMDF>'.$numericCode.'</cMDF><cDV>'.substr($accessKey, -1).'</cDV>'
            .'<modal>1</modal><dhEmi>'.Carbon::parse((string) $snapshot['issue_at'])->format('Y-m-d\TH:i:sP').'</dhEmi>'
            .'<tpEmis>'.e((string) config('dfe.mdfe.tp_emis')).'</tpEmis><procEmi>0</procEmi>'
            .'<verProc>Sistema interno 1.0</verProc>'
            .'<UFIni>'.e((string) $origin['uf']).'</UFIni><UFFim>'.e((string) $destination['uf']).'</UFFim>'
            .$this->loadingCities($snapshot['loading_cities'])
            .$this->routeGroups($routeUfs)
            .'</ide>'
            .$this->emitterGroup()
            .'<infModal versaoModal="'.e((string) config('dfe.mdfe.version')).'"><rodo>'
            .$this->anttGroup($snapshot)
            .$this->vehicleGroup($snapshot['vehicle'])
            .'</rodo></infModal>'
            .$this->destinationGroups($snapshot['destinations'])
            .$this->insuranceGroup($snapshot['insurance'])
            .$this->predominantProductGroup($snapshot['cargo'], $snapshot['inf_lotacao'] ?? null)
            .$this->totalsGroup($snapshot['cargo'], count($ctes))
            .'</infMDFe>'
            .'<infMDFeSupl><qrCodMDFe>'.e($this->qrUrl($accessKey)).'</qrCodMDFe></infMDFeSupl>'
            .'</MDFe>';
    }

    protected function emitterGroup(): string
    {
        $emit = (array) config('dfe.emit');

        return '<emit><CNPJ>'.e((string) $emit['cnpj']).'</CNPJ>'
            .'<IE>'.e((string) $emit['ie']).'</IE><xNome>'.e((string) $emit['xNome']).'</xNome>'
            .'<xFant>'.e((string) $emit['xFant']).'</xFant><enderEmit>'
            .'<xLgr>'.e((string) $emit['xLgr']).'</xLgr><nro>'.e((string) $emit['nro']).'</nro>'
            .'<xBairro>'.e((string) $emit['xBairro']).'</xBairro><cMun>'.e((string) $emit['cMun']).'</cMun>'
            .'<xMun>'.e((string) $emit['xMun']).'</xMun><CEP>'.e((string) $emit['cep']).'</CEP>'
            .'<UF>'.e((string) $emit['uf']).'</UF></enderEmit></emit>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function anttGroup(array $snapshot): string
    {
        return '<infANTT><RNTRC>'.e((string) $snapshot['rntrc']).'</RNTRC>'
            .'<infCIOT><CIOT>'.e((string) $snapshot['ciot']).'</CIOT>'
            .'<CNPJ>'.e((string) $snapshot['ciot_responsible_cnpj']).'</CNPJ></infCIOT>'
            .$this->paymentGroup($snapshot['payment'] ?? null)
            .'</infANTT>';
    }

    /** @param array<string, mixed>|null $payment */
    protected function paymentGroup(?array $payment): string
    {
        if ($payment === null) {
            return '';
        }

        $components = '';

        foreach ($payment['components'] as $component) {
            $components .= '<Comp><tpComp>'.e((string) $component['type']).'</tpComp>'
                .'<vComp>'.e((string) $component['value']).'</vComp></Comp>';
        }

        return '<infPag><xNome>'.e((string) $payment['payer_name']).'</xNome>'
            .'<CNPJ>'.e((string) $payment['payer_cnpj']).'</CNPJ>'
            .$components
            .'<vContrato>'.e((string) $payment['contract_value']).'</vContrato>'
            .'<indPag>'.e((string) $payment['method']).'</indPag>'
            .'<infBanc><codBanco>'.e((string) $payment['bank_code']).'</codBanco>'
            .'<codAgencia>'.e((string) $payment['bank_agency']).'</codAgencia></infBanc></infPag>';
    }

    /**
     * @param  array<string, mixed>  $vehicle
     */
    protected function vehicleGroup(array $vehicle): string
    {
        $renavam = filled($vehicle['renavam'] ?? null) ? '<RENAVAM>'.e((string) $vehicle['renavam']).'</RENAVAM>' : '';
        $capacityKg = filled($vehicle['capacity_kg'] ?? null) ? '<capKG>'.(int) $vehicle['capacity_kg'].'</capKG>' : '';
        $capacityM3 = filled($vehicle['capacity_m3'] ?? null) ? '<capM3>'.(int) $vehicle['capacity_m3'].'</capM3>' : '';
        $drivers = '';

        foreach ($vehicle['drivers'] as $driver) {
            $drivers .= '<condutor><xNome>'.e((string) $driver['name']).'</xNome><CPF>'.e((string) $driver['cpf']).'</CPF></condutor>';
        }

        $trailers = '';

        foreach ($vehicle['trailers'] as $trailer) {
            $trailers .= '<veicReboque><placa>'.e((string) $trailer['plate']).'</placa>'
                .(filled($trailer['renavam'] ?? null) ? '<RENAVAM>'.e((string) $trailer['renavam']).'</RENAVAM>' : '')
                .'<tara>'.(int) $trailer['tare_kg'].'</tara>'
                .(filled($trailer['capacity_kg'] ?? null) ? '<capKG>'.(int) $trailer['capacity_kg'].'</capKG>' : '')
                .(filled($trailer['capacity_m3'] ?? null) ? '<capM3>'.(int) $trailer['capacity_m3'].'</capM3>' : '')
                .'<tpCar>'.e((string) $trailer['body_type']).'</tpCar>'
                .(filled($trailer['uf'] ?? null) ? '<UF>'.e((string) $trailer['uf']).'</UF>' : '')
                .'</veicReboque>';
        }

        $tractor = '<veicTracao><placa>'.e((string) $vehicle['plate']).'</placa>'.$renavam
            .'<tara>'.(int) $vehicle['tare_kg'].'</tara>'.$capacityKg.$capacityM3.$drivers
            .'<tpRod>'.e((string) $vehicle['type_rod']).'</tpRod><tpCar>'.e((string) $vehicle['body_type']).'</tpCar>'
            .(filled($vehicle['uf'] ?? null) ? '<UF>'.e((string) $vehicle['uf']).'</UF>' : '')
            .'</veicTracao>';

        return $tractor.$trailers;
    }

    /**
     * @param  array<int, array{ibge: string, name: string}>  $cities
     */
    protected function loadingCities(array $cities): string
    {
        $xml = '';

        foreach ($cities as $city) {
            $xml .= '<infMunCarrega><cMunCarrega>'.e((string) $city['ibge']).'</cMunCarrega>'
                .'<xMunCarrega>'.e((string) $city['name']).'</xMunCarrega></infMunCarrega>';
        }

        return $xml;
    }

    /**
     * @param  array<int, string>  $routeUfs
     */
    protected function routeGroups(array $routeUfs): string
    {
        return implode('', array_map(
            fn (string $uf): string => '<infPercurso><UFPer>'.e($uf).'</UFPer></infPercurso>',
            $routeUfs,
        ));
    }

    /**
     * @param  array<int, array{ibge: string, name: string, cte_access_keys: array<int, string>}>  $destinations
     */
    protected function destinationGroups(array $destinations): string
    {
        $xml = '<infDoc>';

        foreach ($destinations as $destination) {
            $xml .= '<infMunDescarga><cMunDescarga>'.e((string) $destination['ibge']).'</cMunDescarga>'
                .'<xMunDescarga>'.e((string) $destination['name']).'</xMunDescarga>';

            foreach ($destination['cte_access_keys'] as $accessKey) {
                $xml .= '<infCTe><chCTe>'.e((string) $accessKey).'</chCTe></infCTe>';
            }

            $xml .= '</infMunDescarga>';
        }

        return $xml.'</infDoc>';
    }

    /**
     * @param  array<string, mixed>  $insurance
     */
    protected function insuranceGroup(array $insurance): string
    {
        return '<seg><infResp><respSeg>1</respSeg></infResp><infSeg>'
            .'<xSeg>'.e((string) $insurance['insurer_name']).'</xSeg>'
            .'<CNPJ>'.e((string) $insurance['insurer_cnpj']).'</CNPJ></infSeg>'
            .'<nApol>'.e((string) $insurance['policy_number']).'</nApol>'
            .implode('', array_map(fn (string $number): string => '<nAver>'.e($number).'</nAver>', $insurance['endorsements']))
            .'</seg>';
    }

    /**
     * @param  array<string, mixed>  $cargo
     * @param  array{loading_cep: string, unloading_cep: string}|null  $infLotacao
     */
    protected function predominantProductGroup(array $cargo, ?array $infLotacao): string
    {
        $ncm = filled($cargo['ncm'] ?? null) ? '<NCM>'.e((string) $cargo['ncm']).'</NCM>' : '';

        return '<prodPred><tpCarga>'.e((string) $cargo['type']).'</tpCarga>'
            .'<xProd>'.e((string) $cargo['predominant_product']).'</xProd>'
            .$ncm
            .$this->infLotacaoGroup($infLotacao)
            .'</prodPred>';
    }

    /** @param array{loading_cep: string, unloading_cep: string}|null $infLotacao */
    protected function infLotacaoGroup(?array $infLotacao): string
    {
        if ($infLotacao === null) {
            return '';
        }

        return '<infLotacao><infLocalCarrega><CEP>'.e($infLotacao['loading_cep']).'</CEP></infLocalCarrega>'
            .'<infLocalDescarrega><CEP>'.e($infLotacao['unloading_cep']).'</CEP></infLocalDescarrega></infLotacao>';
    }

    /**
     * @param  array<string, mixed>  $cargo
     */
    protected function totalsGroup(array $cargo, int $cteCount): string
    {
        return '<tot><qCTe>'.$cteCount.'</qCTe><vCarga>'.e((string) $cargo['value']).'</vCarga>'
            .'<cUnid>01</cUnid><qCarga>'.e((string) $cargo['weight_kg']).'</qCarga></tot>';
    }

    protected function qrUrl(string $accessKey): string
    {
        return rtrim((string) config('dfe.mdfe.qr_base_url'), '/').'?chMDFe='.$accessKey.'&tpAmb='.(string) config('dfe.tp_amb');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, string>
     */
    protected function routeUfs(array $snapshot): array
    {
        $routeUfs = array_values(array_unique(array_filter(array_map(
            fn (string $uf): string => strtoupper(trim($uf)),
            $snapshot['route_ufs'],
        ))));

        if ($snapshot['origin']['uf'] !== $snapshot['destination']['uf'] && $routeUfs === []) {
            throw new DomainException('Informe manualmente as UFs intermediárias do percurso interestadual do MDF-e.');
        }

        return $routeUfs;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function assertSnapshotComplete(array $snapshot): void
    {
        if (in_array($snapshot['ciot_source'] ?? null, ['synthetic_homologation', 'homologation_fixture'], true)) {
            throw new DomainException('CIOT sintético de homologação não pode ser usado para emitir MDF-e.');
        }

        $required = ['issue_at', 'ciot', 'ciot_responsible_cnpj', 'rntrc', 'origin', 'destination', 'loading_cities', 'destinations', 'vehicle', 'cargo', 'insurance'];
        $missing = array_values(array_filter($required, fn (string $key): bool => blank($snapshot[$key] ?? null)));

        if (! array_key_exists('route_ufs', $snapshot) || ! is_array($snapshot['route_ufs'])) {
            $missing[] = 'route_ufs';
        }

        if ($missing !== []) {
            throw new DomainException('Snapshot do MDF-e incompleto: '.implode(', ', $missing).'.');
        }

        if (($snapshot['inf_lotacao'] ?? null) !== null && blank($snapshot['payment'] ?? null)) {
            throw new DomainException('Informe os dados de pagamento exigidos para o MDF-e de carga lotação.');
        }

        $ncm = $snapshot['cargo']['ncm'] ?? null;

        if (filled($ncm)) {
            if (! preg_match('/^(?:\d{2}|\d{8})$/', (string) $ncm)) {
                throw new DomainException('O NCM do produto predominante deve conter 2 ou 8 dígitos.');
            }
        }

        if (! preg_match('/^\d{12}$/', (string) $snapshot['ciot'])) {
            throw new DomainException('CIOT do MDF-e deve conter 12 dígitos reais.');
        }

        if (! preg_match('/^\d{14}$/', (string) $snapshot['ciot_responsible_cnpj'])) {
            throw new DomainException('CNPJ responsável pela geração do CIOT deve conter 14 dígitos.');
        }

        $cteCount = array_sum(array_map(
            fn (array $destination): int => count((array) ($destination['cte_access_keys'] ?? [])),
            (array) $snapshot['destinations'],
        ));
        $requiresLotacao = (string) ($snapshot['tp_emit'] ?? config('dfe.mdfe.tp_emit')) === '1' && $cteCount === 1;

        if ($requiresLotacao && blank($snapshot['inf_lotacao'] ?? null)) {
            throw new DomainException('O grupo infLotacao é obrigatório para MDF-e rodoviário de tpEmit 1 com um único DF-e.');
        }

        if (is_array($snapshot['inf_lotacao'] ?? null)) {
            foreach (['loading_cep', 'unloading_cep'] as $field) {
                if (! preg_match('/^\d{8}$/', (string) ($snapshot['inf_lotacao'][$field] ?? ''))) {
                    throw new DomainException('infLotacao exige CEP de oito dígitos para carregamento e descarregamento.');
                }
            }
        }

        if (($snapshot['insurance']['source'] ?? null) === 'homologation_fixture' && (string) config('dfe.tp_amb') === '1') {
            throw new DomainException('Fixture sintético de seguro pode ser usado somente em homologação.');
        }

        $this->insuranceSnapshot->validate($snapshot['insurance']);

        $this->routeUfs($snapshot);
        $this->snapshotValidator->assertLoadingCitiesMatchOrigin($snapshot);
    }
}
