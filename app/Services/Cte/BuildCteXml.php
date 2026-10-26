<?php

namespace App\Services\Cte;

use App\Services\Dfe\AccessKey;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;

/**
 * CT-e construído do snapshot para transmissão (spec §2.4, §2.5): numeração
 * atômica, chave/cDV, regras de ambiente derivadas de dfe.tp_amb e ordem
 * estrita de xs:sequence dos gabaritos autorizados (2962/2963). Saída = XML
 * assinável (sem Signature — o XmlSigner entra depois, F3).
 *
 * O snapshot v2 mantém os blocos fiscais de rem/dest; o v3 de álcool adiciona
 * referências múltiplas de NF-e e os dados da entrega física usados na rota.
 */
class BuildCteXml
{
    protected const HOMOLOG_PARTY_CNPJ = '00000000000191';

    protected const HOMOLOG_PARTY_NAME = 'CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';

    public function __construct(
        protected AllocateCteNumber $allocator,
        protected AccessKey $accessKey,
        protected ValidateGeneralCargoCteSnapshot $generalCargoValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(array $snapshot): BuiltCte
    {
        $this->assertSnapshotComplete($snapshot);

        $serie = (int) config('dfe.cte.serie');
        $number = $this->allocator->handle();
        $cct = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $accessKey = $this->accessKey->build(
            cnpj: (string) config('dfe.emit.cnpj'),
            aamm: $this->emissionDate($snapshot)->format('ym'),
            serie: $serie,
            number: $number,
            modelo: AccessKey::MODELO_CTE,
            tpEmis: (string) config('dfe.cte.tpEmis'),
            cct: $cct,
        );

        return new BuiltCte(
            xml: $this->render($snapshot, $accessKey, $number, $serie, $cct),
            accessKey: $accessKey,
            number: $number,
            serie: $serie,
            cct: $cct,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function render(array $snapshot, string $accessKey, int $number, int $serie, string $cct): string
    {
        $emittedAt = $this->emissionDate($snapshot);
        $isHomolog = (string) config('dfe.tp_amb') !== '1';
        $isGeneralCargo = $this->isGeneralCargoLine($snapshot);

        if ($isGeneralCargo && (string) ($snapshot['ind_ietoma'] ?? '') === '2') {
            $snapshot = $this->snapshotWithIsentoTaker($snapshot);
        }

        $freight = $this->decimal($isGeneralCargo ? $snapshot['freight']['total'] : $snapshot['value'], 2);
        $cfop = $isGeneralCargo ? (string) $snapshot['cfop'] : (string) config('dfe.cte.cfop');

        if ($this->isVehicleLine($snapshot)
            && (string) $snapshot['origin']['uf'] !== 'SP'
            && (string) $snapshot['destination']['uf'] === 'SP') {
            $cfop = '6932';
        }

        $nature = $isGeneralCargo ? (string) $snapshot['nat_op'] : (string) config('dfe.cte.natOp');
        $takerIndicator = $isGeneralCargo
            ? ($isHomolog ? '9' : (string) $snapshot['ind_ietoma'])
            : $this->takerIeIndicator($snapshot, $isHomolog);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CTe xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<infCte Id="CTe'.$accessKey.'" versao="'.$this->version().'">'
            .'<ide>'
            .'<cUF>'.(int) config('dfe.cuf').'</cUF>'
            .'<cCT>'.str_pad($cct, 8, '0', STR_PAD_LEFT).'</cCT>'
            .'<CFOP>'.e($cfop).'</CFOP>'
            .'<natOp>'.e($nature).'</natOp>'
            .'<mod>'.AccessKey::MODELO_CTE.'</mod>'
            .'<serie>'.$serie.'</serie>'
            .'<nCT>'.$number.'</nCT>'
            .'<dhEmi>'.$emittedAt->format('Y-m-d\TH:i:sP').'</dhEmi>'
            .'<tpImp>1</tpImp>'
            .'<tpEmis>'.e((string) config('dfe.cte.tpEmis')).'</tpEmis>'
            .'<cDV>'.substr($accessKey, -1).'</cDV>'
            .'<tpAmb>'.($isHomolog ? '2' : '1').'</tpAmb>'
            .'<tpCTe>0</tpCTe>'
            .'<procEmi>0</procEmi>'
            .'<verProc>'.e((string) config('dfe.cte.verProc')).'</verProc>'
            .'<cMunEnv>'.e((string) config('dfe.emit.cMun')).'</cMunEnv>'
            .'<xMunEnv>'.e((string) config('dfe.emit.xMun')).'</xMunEnv>'
            .'<UFEnv>'.e((string) config('dfe.emit.uf')).'</UFEnv>'
            .'<modal>'.e((string) config('dfe.cte.modal')).'</modal>'
            .'<tpServ>0</tpServ>'
            .$this->cityGroup('Ini', $snapshot['origin'])
            .$this->cityGroup('Fim', $snapshot['destination'])
            .'<retira>0</retira>'
            .'<indIEToma>'.$takerIndicator.'</indIEToma>'
            .$this->takerGroup($snapshot, $isHomolog)
            .'</ide>'
            .'<compl><Entrega><semData><tpPer>0</tpPer></semData><semHora><tpHor>0</tpHor></semHora></Entrega></compl>'
            .$this->emitGroup()
            .$this->partyGroup('rem', $snapshot['rem'], $isHomolog, withFantasyName: true)
            .($isGeneralCargo ? $this->optionalPartyGroup('exped', $snapshot, $isHomolog) : '')
            .($isGeneralCargo ? $this->optionalPartyGroup('receb', $snapshot, $isHomolog) : '')
            .$this->partyGroup('dest', $snapshot['dest'], $isHomolog, withFantasyName: false)
            .$this->freightGroup($snapshot, $freight)
            .'<imp><ICMS><ICMSSN><CST>90</CST><indSN>1</indSN></ICMSSN></ICMS></imp>'
            .'<infCTeNorm>'
            .$this->cargoGroup($snapshot)
            .$this->documentGroup($snapshot)
            .'<infModal versaoModal="'.$this->version().'"><rodo><RNTRC>'.e((string) config('dfe.cte.rntrc')).'</RNTRC></rodo></infModal>'
            .'</infCTeNorm>'
            .'</infCte>'
            .'<infCTeSupl xmlns="http://www.portalfiscal.inf.br/cte"><qrCodCTe><![CDATA['.$this->qrUrl($accessKey).']]></qrCodCTe></infCTeSupl>'
            .'</CTe>';

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $city
     */
    protected function cityGroup(string $suffix, array $city): string
    {
        return '<cMun'.$suffix.'>'.e((string) $city['ibge']).'</cMun'.$suffix.'>'
            .'<xMun'.$suffix.'>'.e((string) $city['name']).'</xMun'.$suffix.'>'
            .'<UF'.$suffix.'>'.e((string) $city['uf']).'</UF'.$suffix.'>';
    }

    protected function emitGroup(): string
    {
        $emit = (array) config('dfe.emit');

        return '<emit>'
            .'<CNPJ>'.e((string) $emit['cnpj']).'</CNPJ>'
            .'<IE>'.e((string) $emit['ie']).'</IE>'
            .'<xNome>'.e((string) $emit['xNome']).'</xNome>'
            .'<xFant>'.e((string) $emit['xFant']).'</xFant>'
            .'<enderEmit>'
            .'<xLgr>'.e((string) $emit['xLgr']).'</xLgr>'
            .'<nro>'.e((string) $emit['nro']).'</nro>'
            .'<xBairro>'.e((string) $emit['xBairro']).'</xBairro>'
            .'<cMun>'.e((string) $emit['cMun']).'</cMun>'
            .'<xMun>'.e((string) $emit['xMun']).'</xMun>'
            .'<CEP>'.e((string) $emit['cep']).'</CEP>'
            .'<UF>'.e((string) $emit['uf']).'</UF>'
            .'</enderEmit>'
            .'<CRT>'.e((string) $emit['crt']).'</CRT>'
            .'</emit>';
    }

    /**
     * rem/dest (spec §2.4): em homologação CNPJ/nome de letreiro, sem IE e sem
     * xFant (gabarito homolog autorizado); em produção os dados reais do
     * snapshot — rem mantém xFant (2962), dest nunca teve xFant.
     *
     * @param  array<string, mixed>  $party
     */
    protected function partyGroup(string $tag, array $party, bool $isHomolog, bool $withFantasyName): string
    {
        $addressTag = match ($tag) {
            'rem' => 'enderReme',
            'dest' => 'enderDest',
            'exped' => 'enderExped',
            'receb' => 'enderReceb',
            default => throw new DomainException("Grupo de participante CT-e não suportado: {$tag}."),
        };

        $identity = $isHomolog
            ? '<CNPJ>'.self::HOMOLOG_PARTY_CNPJ.'</CNPJ>'
            : $this->partyIdentity($party);
        $name = $isHomolog ? self::HOMOLOG_PARTY_NAME : (string) $party['xNome'];
        $fantasy = ! $isHomolog && $withFantasyName
            ? '<xFant>'.e((string) ($party['xFant'] ?? $party['xNome'])).'</xFant>'
            : '';
        $ie = ! $isHomolog && filled($party['ie'] ?? null) ? '<IE>'.e((string) $party['ie']).'</IE>' : '';

        return '<'.$tag.'>'
            .$identity
            .$ie
            .'<xNome>'.e($name).'</xNome>'
            .$fantasy
            .$this->addressGroup($addressTag, $party)
            .'</'.$tag.'>';
    }

    /** @param array<string, mixed> $snapshot */
    private function optionalPartyGroup(string $tag, array $snapshot, bool $isHomolog): string
    {
        $party = $snapshot[$tag] ?? null;

        return is_array($party) ? $this->partyGroup($tag, $party, $isHomolog, withFantasyName: false) : '';
    }

    /** @param array<string, mixed> $party */
    private function addressGroup(string $tag, array $party): string
    {
        return '<'.$tag.'>'
            .'<xLgr>'.e((string) $party['xLgr']).'</xLgr>'
            .'<nro>'.e((string) $party['nro']).'</nro>'
            .$this->complementElement($party)
            .'<xBairro>'.e((string) $party['xBairro']).'</xBairro>'
            .'<cMun>'.e((string) $party['cMun']).'</cMun>'
            .'<xMun>'.e((string) $party['xMun']).'</xMun>'
            .'<CEP>'.e((string) ($party['CEP'] ?? $party['cep'] ?? '')).'</CEP>'
            .'<UF>'.e((string) ($party['UF'] ?? $party['uf'] ?? '')).'</UF>'
            .'<cPais>1058</cPais><xPais>Brasil</xPais>'
            .'</'.$tag.'>';
    }

    /** @param array<string, mixed> $snapshot */
    private function takerGroup(array $snapshot, bool $isHomolog): string
    {
        if (! $this->isGeneralCargoLine($snapshot)) {
            return '<toma3><toma>3</toma></toma3>';
        }

        $code = (string) $snapshot['taker_code'];

        if ($code !== '4') {
            return '<toma3><toma>'.e($code).'</toma></toma3>';
        }

        $party = (array) $snapshot['taker'];
        $identity = $isHomolog
            ? '<CNPJ>'.self::HOMOLOG_PARTY_CNPJ.'</CNPJ>'
            : $this->partyIdentity($party);
        $name = $isHomolog ? self::HOMOLOG_PARTY_NAME : (string) $party['xNome'];
        $ie = ! $isHomolog && filled($party['ie'] ?? null) ? '<IE>'.e((string) $party['ie']).'</IE>' : '';

        return '<toma4><toma>4</toma>'.$identity.$ie.'<xNome>'.e($name).'</xNome>'
            .$this->addressGroup('enderToma', $party)
            .'</toma4>';
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function snapshotWithIsentoTaker(array $snapshot): array
    {
        $partyKey = match ((string) ($snapshot['taker_code'] ?? '')) {
            '0' => 'rem',
            '1' => 'exped',
            '2' => 'receb',
            '3' => 'dest',
            '4' => 'taker',
            default => null,
        };

        if ($partyKey !== null && is_array($snapshot[$partyKey] ?? null)) {
            $snapshot[$partyKey]['ie'] = 'ISENTO';
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $party */
    private function partyIdentity(array $party): string
    {
        $cnpj = preg_replace('/\D/', '', (string) ($party['cnpj'] ?? '')) ?: '';
        $cpf = preg_replace('/\D/', '', (string) ($party['cpf'] ?? '')) ?: '';

        if (($cnpj === '') === ($cpf === '')) {
            throw new DomainException('Cada parte fiscal deve informar exatamente um CNPJ ou CPF.');
        }

        if ($cnpj !== '') {
            return '<CNPJ>'.e($cnpj).'</CNPJ>';
        }

        return '<CPF>'.e($cpf).'</CPF>';
    }

    /** @param array<string, mixed> $party */
    private function complementElement(array $party): string
    {
        return filled($party['xCpl'] ?? null) ? '<xCpl>'.e((string) $party['xCpl']).'</xCpl>' : '';
    }

    /** @param array<string, mixed> $snapshot */
    private function takerIeIndicator(array $snapshot, bool $isHomolog): string
    {
        if ($isHomolog) {
            return '9';
        }

        $ie = mb_strtoupper(trim((string) ($snapshot['dest']['ie'] ?? '')));

        if ($ie === '') {
            return '9';
        }

        return $ie === 'ISENTO' ? '2' : '1';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function cargoGroup(array $snapshot): string
    {
        if ($this->isGeneralCargoLine($snapshot)) {
            $cargo = $snapshot['cargo'];
            $xml = '<infCarga>'
                .'<vCarga>'.$this->decimal($cargo['value'], 2).'</vCarga>'
                .'<proPred>'.e((string) $cargo['predominant_product']).'</proPred>';

            if (filled($cargo['other_category'] ?? null)) {
                $xml .= '<xOutCat>'.e((string) $cargo['other_category']).'</xOutCat>';
            }

            foreach ((array) $cargo['measurements'] as $measurement) {
                $xml .= '<infQ><cUnid>'.e((string) $measurement['unit']).'</cUnid>'
                    .'<tpMed>'.e((string) $measurement['measure']).'</tpMed>'
                    .'<qCarga>'.$this->decimal($measurement['quantity'], 4).'</qCarga></infQ>';
            }

            return $xml.'</infCarga>';
        }

        if ($this->isVehicleLine($snapshot)) {
            $proPred = strtoupper((string) $snapshot['vehicle_model']).'-PLACA:'.strtoupper((string) $snapshot['vehicle_plate']);
            $weight = $this->decimal($snapshot['cargo_weight_kg'] ?? config('dfe.cte.default_weight_kg'), 4);

            return '<infCarga>'
                .'<vCarga>'.$this->decimal($snapshot['fipe_value'], 2).'</vCarga>'
                .'<proPred>'.e($proPred).'</proPred>'
                .'<xOutCat>'.e((string) $snapshot['payment_code']).'</xOutCat>'
                .'<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>'.$weight.'</qCarga></infQ>'
                .'</infCarga>';
        }

        return '<infCarga>'
            .'<vCarga>'.$this->decimal($snapshot['cargo_value'], 2).'</vCarga>'
            .'<proPred>ETANOL HIDRATADO COMBUSTIVEL</proPred>'
            .'<infQ><cUnid>04</cUnid><tpMed>LITROS</tpMed><qCarga>'.$this->decimal($snapshot['cargo_liters'], 4).'</qCarga></infQ>'
            .'</infCarga>';
    }

    /**
     * Bifurcação por linha (spec §2.5): veículos = infOutros (carta de remoção);
     * tanque = infNFe (chave + dPrev).
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function documentGroup(array $snapshot): string
    {
        if ($this->isVehicleLine($snapshot)) {
            $emittedOn = $this->emissionDate($snapshot)->format('Y-m-d');

            return '<infDoc><infOutros>'
                .'<tpDoc>99</tpDoc>'
                .'<descOutros>CARTA DE REMOCAO</descOutros>'
                .'<nDoc>0</nDoc>'
                .'<dEmi>'.e((string) ($snapshot['removal_letter_date'] ?? $emittedOn)).'</dEmi>'
                .'</infOutros></infDoc>';
        }

        if ($this->isGeneralCargoLine($snapshot) && ($snapshot['origin_document_mode'] ?? null) === 'other') {
            $document = (array) $snapshot['other_document'];

            return '<infDoc><infOutros>'
                .'<tpDoc>99</tpDoc>'
                .'<descOutros>'.e((string) $document['description']).'</descOutros>'
                .(filled($document['number'] ?? null) ? '<nDoc>'.e((string) $document['number']).'</nDoc>' : '')
                .(filled($document['issued_on'] ?? null) ? '<dEmi>'.e((string) $document['issued_on']).'</dEmi>' : '')
                .'</infOutros></infDoc>';
        }

        $references = $snapshot['nfe_documents'] ?? [[
            'access_key' => (string) ($snapshot['nfe_key'] ?? ''),
        ]];
        $documents = '';

        foreach ($references as $reference) {
            $documents .= '<infNFe>'
                .'<chave>'.e((string) $reference['access_key']).'</chave>'
                .($this->isGeneralCargoLine($snapshot) ? '' : '<dPrev>'.e((string) $snapshot['dprev']).'</dPrev>')
                .'</infNFe>';
        }

        return '<infDoc>'.$documents.'</infDoc>';
    }

    /** @param array<string, mixed> $snapshot */
    private function freightGroup(array $snapshot, string $freight): string
    {
        if (! $this->isGeneralCargoLine($snapshot)) {
            return '<vPrest><vTPrest>'.$freight.'</vTPrest><vRec>'.$freight.'</vRec>'
                .($this->isVehicleLine($snapshot) ? '<Comp><xNome>FRETE</xNome><vComp>'.$freight.'</vComp></Comp>' : '')
                .'</vPrest>';
        }

        $group = '<vPrest><vTPrest>'.$freight.'</vTPrest>'
            .'<vRec>'.$this->decimal($snapshot['freight']['receivable'], 2).'</vRec>';

        foreach ((array) $snapshot['freight']['components'] as $component) {
            $group .= '<Comp><xNome>'.e((string) $component['name']).'</xNome>'
                .'<vComp>'.$this->decimal($component['value'], 2).'</vComp></Comp>';
        }

        return $group.'</vPrest>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function isVehicleLine(array $snapshot): bool
    {
        return ($snapshot['line'] ?? 'vehicle_removal') === 'vehicle_removal';
    }

    /** @param array<string, mixed> $snapshot */
    private function isGeneralCargoLine(array $snapshot): bool
    {
        return ($snapshot['line'] ?? null) === 'general_cargo';
    }

    protected function qrUrl(string $accessKey): string
    {
        $base = (string) env('DFE_QR_BASE_URL', match ((string) config('dfe.tp_amb')) {
            '1' => 'https://nfe.fazenda.sp.gov.br/CTeConsulta',
            default => 'https://homologacao.nfe.fazenda.sp.gov.br/CTeConsulta',
        });

        return rtrim($base, '/').'/qrCode?chCTe='.$accessKey.'&tpAmb='.(string) config('dfe.tp_amb');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function assertSnapshotComplete(array $snapshot): void
    {
        $line = (string) ($snapshot['line'] ?? 'vehicle_removal');

        if ($line === 'general_cargo') {
            $this->generalCargoValidator->handle($snapshot, forEmission: true);

            return;
        }

        if (! in_array($line, ['vehicle_removal', 'tank_alcohol'], true)) {
            throw new DomainException("Modalidade de CT-e não reconhecida: {$line}.");
        }

        $missing = [];

        if (isset($snapshot['schema_version']) && (int) $snapshot['schema_version'] < 2) {
            throw new DomainException('Snapshot v1 não suportado pelo emissor direto: rem/dest fiscais não foram capturados (spec §2.5).');
        }

        foreach (['value', 'origin', 'destination', 'rem', 'dest'] as $field) {
            if (blank($snapshot[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        foreach (['origin', 'destination'] as $field) {
            foreach (['ibge', 'name', 'uf'] as $key) {
                if (blank($snapshot[$field][$key] ?? null)) {
                    $missing[] = $field.'.'.$key;
                }
            }
        }

        foreach (['rem', 'dest'] as $field) {
            foreach (['xNome', 'xLgr', 'nro', 'xBairro', 'cMun', 'xMun', 'cep', 'uf'] as $key) {
                if (blank($snapshot[$field][$key] ?? null)) {
                    $missing[] = $field.'.'.$key;
                }
            }

            $cnpj = preg_replace('/\D/', '', (string) ($snapshot[$field]['cnpj'] ?? '')) ?: '';
            $cpf = preg_replace('/\D/', '', (string) ($snapshot[$field]['cpf'] ?? '')) ?: '';

            if (($cnpj === '') === ($cpf === '')
                || ($cnpj !== '' && ! preg_match('/^\d{14}$/', $cnpj))
                || ($cpf !== '' && ! preg_match('/^\d{11}$/', $cpf))) {
                $missing[] = $field.'.cnpj/cpf';
            }
        }

        if ($this->isVehicleLine($snapshot)) {
            foreach (['vehicle_model', 'vehicle_plate', 'payment_code', 'fipe_value'] as $field) {
                if (blank($snapshot[$field] ?? null)) {
                    $missing[] = $field;
                }
            }
        } else {
            foreach (['dprev', 'cargo_value', 'cargo_liters'] as $field) {
                if (blank($snapshot[$field] ?? null)) {
                    $missing[] = $field;
                }
            }

            $references = $snapshot['nfe_documents'] ?? null;
            $schemaVersion = (int) ($snapshot['schema_version'] ?? 2);

            if (is_array($references) && array_is_list($references) && $references !== []) {
                $seenKeys = [];

                foreach ($references as $reference) {
                    $accessKey = is_array($reference) ? (string) ($reference['access_key'] ?? '') : '';

                    if (! preg_match('/^\d{44}$/', $accessKey) || isset($seenKeys[$accessKey])) {
                        $missing[] = 'nfe_documents (chaves de 44 dígitos únicas)';
                        break;
                    }

                    $seenKeys[$accessKey] = true;
                }
            } elseif ($schemaVersion < 3
                && filled($snapshot['nfe_key'] ?? null)
                && preg_match('/^\d{44}$/', (string) $snapshot['nfe_key'])) {
                $references = [['access_key' => (string) $snapshot['nfe_key']]];
            } else {
                $missing[] = 'nfe_documents ou nfe_key';
                $references = [];
            }

            if (count($references) > (int) config('dfe.nfe_xml_max_files_per_import', 10)) {
                $missing[] = 'nfe_documents (limite de NF-es)';
            }

            if (filled($snapshot['dprev'] ?? null)) {
                $date = (string) $snapshot['dprev'];

                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $missing[] = 'dprev (AAAA-MM-DD)';
                } else {
                    [$year, $month, $day] = array_map('intval', explode('-', $date));

                    if (! checkdate($month, $day, $year)) {
                        $missing[] = 'dprev (data válida)';
                    } elseif ($schemaVersion >= 3 && $date < now('America/Sao_Paulo')->toDateString()) {
                        $missing[] = 'dprev (previsão não vencida)';
                    }
                }
            }

            if ($schemaVersion >= 3 && ! is_array($snapshot['delivery_party'] ?? null)) {
                $missing[] = 'delivery_party';
            }

            if (isset($snapshot['delivery_party']) && is_array($snapshot['delivery_party'])) {
                foreach (['xNome', 'xLgr', 'nro', 'xBairro', 'cMun', 'xMun', 'cep', 'uf'] as $field) {
                    if (blank($snapshot['delivery_party'][$field] ?? null)) {
                        $missing[] = 'delivery_party.'.$field;
                    }
                }

                foreach ((array) ($snapshot['delivery_missing_fields'] ?? []) as $field) {
                    $missing[] = 'delivery_party.'.(string) $field;
                }

                $deliveryCnpj = preg_replace('/\D/', '', (string) ($snapshot['delivery_party']['cnpj'] ?? '')) ?: '';
                $deliveryCpf = preg_replace('/\D/', '', (string) ($snapshot['delivery_party']['cpf'] ?? '')) ?: '';

                if (($deliveryCnpj === '') === ($deliveryCpf === '')
                    || ($deliveryCnpj !== '' && ! preg_match('/^\d{14}$/', $deliveryCnpj))
                    || ($deliveryCpf !== '' && ! preg_match('/^\d{11}$/', $deliveryCpf))) {
                    $missing[] = 'delivery_party.cnpj/cpf';
                }

                if ((string) ($snapshot['destination']['ibge'] ?? '') !== (string) $snapshot['delivery_party']['cMun']
                    || (string) ($snapshot['destination']['name'] ?? '') !== (string) $snapshot['delivery_party']['xMun']
                    || (string) ($snapshot['destination']['uf'] ?? '') !== (string) $snapshot['delivery_party']['uf']) {
                    $missing[] = 'destination (destino da entrega física)';
                }
            }

        }

        if ((string) config('dfe.tp_amb') === '1'
            && mb_strtoupper(trim((string) ($snapshot['dest']['ie'] ?? ''))) === 'ISENTO'
            && strtoupper((string) ($snapshot['dest']['uf'] ?? '')) === 'SP') {
            throw new DomainException('O tomador fiscal em SP não pode ser informado como ISENTO na inscrição estadual.');
        }

        if ($missing !== []) {
            throw new DomainException('Snapshot incompleto para o emissor direto: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function emissionDate(array $snapshot): CarbonInterface
    {
        return isset($snapshot['emitted_at'])
            ? Carbon::parse((string) $snapshot['emitted_at'])->timezone('America/Sao_Paulo')
            : now()->timezone('America/Sao_Paulo');
    }

    protected function decimal(mixed $value, int $decimals): string
    {
        return number_format((float) str_replace(',', '.', (string) $value), $decimals, '.', '');
    }

    protected function version(): string
    {
        return (string) config('dfe.cte_version', '4.00');
    }
}
