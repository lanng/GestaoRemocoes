<?php

namespace App\Services\Cte;

use App\Services\Dfe\AccessKey;
use DomainException;

class ValidateGeneralCargoCteSnapshot
{
    private const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    private const TRANSPORT_CFOPS = [
        '5351', '5352', '5353', '5354', '5355', '5356', '5357', '5359', '5360', '5932',
        '6351', '6352', '6353', '6354', '6355', '6356', '6357', '6359', '6360', '6932',
        '7358',
    ];

    private const ISENTO_RESTRICTED_UFS = ['AM', 'BA', 'CE', 'GO', 'MG', 'MS', 'MT', 'PE', 'RN', 'SE', 'SP'];

    public function handle(array $snapshot, bool $forEmission = false): void
    {
        $schemaVersion = (int) ($snapshot['schema_version'] ?? 0);

        if (! in_array($schemaVersion, [4, 5], true) || ($snapshot['line'] ?? null) !== 'general_cargo') {
            throw new DomainException('O CT-e avulso não possui um snapshot de carga genérica válido.');
        }

        $origin = (array) ($snapshot['origin'] ?? []);
        $destination = (array) ($snapshot['destination'] ?? []);
        $originUf = strtoupper((string) ($origin['uf'] ?? ''));
        $destinationUf = strtoupper((string) ($destination['uf'] ?? ''));
        $emitterUf = strtoupper((string) config('dfe.emit.uf'));

        $this->assertCity($origin, 'origem');
        $this->assertCity($destination, 'destino');

        if ($emitterUf !== 'SP') {
            throw new DomainException('O perfil avulso desta empresa está configurado para emitente de SP.');
        }

        $cfop = trim((string) ($snapshot['cfop'] ?? ''));
        $this->assertCfop($cfop, $originUf, $destinationUf, $forEmission);

        $references = (array) ($snapshot['nfe_documents'] ?? []);

        if ($schemaVersion < 5) {
            $this->assertNfeReferences($references);
        } else {
            $this->assertOriginDocument($snapshot, $references);
        }

        $this->assertParty((array) ($snapshot['rem'] ?? []), 'remetente');
        $this->assertParty((array) ($snapshot['dest'] ?? []), 'destinatário');

        if ($schemaVersion >= 5 && (is_array($snapshot['exped'] ?? null) || is_array($snapshot['receb'] ?? null))) {
            throw new DomainException('O novo fluxo avulso não aceita expedidor ou recebedor.');
        }

        foreach (['exped' => 'expedidor', 'receb' => 'recebedor'] as $field => $label) {
            if (is_array($snapshot[$field] ?? null)) {
                $this->assertParty($snapshot[$field], $label);
            }
        }

        $takerCode = (string) ($snapshot['taker_code'] ?? '');

        if ($schemaVersion >= 5 && ! in_array($takerCode, ['0', '3', '4'], true)) {
            throw new DomainException('Selecione remetente, destinatário ou outro como tomador do serviço.');
        }

        $taker = match ($takerCode) {
            '0' => (array) $snapshot['rem'],
            '1' => (array) ($snapshot['exped'] ?? []),
            '2' => (array) ($snapshot['receb'] ?? []),
            '3' => (array) $snapshot['dest'],
            '4' => (array) ($snapshot['taker'] ?? []),
            default => [],
        };

        if ($taker === []) {
            throw new DomainException('Selecione uma parte existente como tomador ou preencha os dados do tomador “outros”.');
        }

        if ($takerCode === '4') {
            $this->assertParty($taker, 'tomador');
        }

        $takerIe = mb_strtoupper(trim((string) ($taker['ie'] ?? '')));
        $takerIeIndicator = (string) ($snapshot['ind_ietoma'] ?? '');

        if (! in_array($takerIeIndicator, ['1', '2', '9'], true)) {
            throw new DomainException('Informe se o tomador é contribuinte, isento ou não contribuinte de ICMS.');
        }

        if ($takerIeIndicator === '1' && ($takerIe === '' || $takerIe === 'ISENTO')) {
            throw new DomainException('Tomador contribuinte de ICMS precisa de uma inscrição estadual numérica.');
        }

        if ($takerIeIndicator === '2' && $takerIe !== '' && $takerIe !== 'ISENTO') {
            throw new DomainException('Tomador isento deve ter inscrição vazia ou literal ISENTO.');
        }

        if ($forEmission && $takerIeIndicator === '2'
            && in_array(strtoupper((string) ($taker['UF'] ?? '')), self::ISENTO_RESTRICTED_UFS, true)) {
            throw new DomainException('A UF do tomador não permite tomador isento de inscrição estadual no CT-e.');
        }

        if ($forEmission && ($snapshot['taker_is_transport_company'] ?? null) !== false) {
            throw new DomainException('Este fluxo normal ainda não aceita tomador que seja transportador; confirme o serviço antes de emitir.');
        }

        $this->assertCargo((array) ($snapshot['cargo'] ?? []));

        if (filled($snapshot['cargo_weight_kg'] ?? null)) {
            $this->decimal((string) $snapshot['cargo_weight_kg'], 2, 'cargo_weight_kg');
        }

        $this->assertFreight((array) ($snapshot['freight'] ?? []));

        if ($forEmission && blank($cfop)) {
            throw new DomainException('Informe o CFOP aplicável à operação antes de enfileirar a emissão.');
        }

        if (blank($snapshot['nat_op'] ?? null) || mb_strlen((string) $snapshot['nat_op']) > 60) {
            throw new DomainException('Informe a natureza da operação com até 60 caracteres.');
        }
    }

    /** @param list<array<string, mixed>> $references */
    private function assertOriginDocument(array $snapshot, array $references): void
    {
        $mode = (string) ($snapshot['origin_document_mode'] ?? '');
        $otherDocument = $snapshot['other_document'] ?? null;

        if ($mode === 'nfe') {
            if ($otherDocument !== null) {
                throw new DomainException('O snapshot não pode misturar NF-e e Carta de remoção.');
            }

            $this->assertNfeReferences($references);

            return;
        }

        if ($mode !== 'other' || $references !== [] || ! is_array($otherDocument)) {
            throw new DomainException('Selecione somente NF-e ou Carta de remoção como documento originário.');
        }

        if (($otherDocument['type'] ?? null) !== 'carta_remocao'
            || ($otherDocument['tp_doc'] ?? null) !== '99'
            || ($otherDocument['description'] ?? null) !== 'CARTA DE REMOCAO') {
            throw new DomainException('A Carta de remoção possui tipo ou descrição inválidos.');
        }

        $number = $otherDocument['number'] ?? null;

        if ($number !== null && (trim((string) $number) === '' || mb_strlen((string) $number) > 20)) {
            throw new DomainException('O número real da Carta de remoção deve ter até 20 caracteres.');
        }

        $issuedOn = $otherDocument['issued_on'] ?? null;

        if ($issuedOn !== null) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $issuedOn)) {
                throw new DomainException('A data real da Carta de remoção deve usar AAAA-MM-DD.');
            }

            [$year, $month, $day] = array_map('intval', explode('-', (string) $issuedOn));

            if (! checkdate($month, $day, $year)) {
                throw new DomainException('A data real da Carta de remoção é inválida.');
            }
        }

        $attachment = $otherDocument['attachment'] ?? null;

        if ($attachment === null) {
            return;
        }

        if (! is_array($attachment)
            || ! is_string($attachment['filename'] ?? null)
            || basename(str_replace('\\', '/', $attachment['filename'])) !== $attachment['filename']
            || mb_strlen($attachment['filename']) > 255
            || ! is_string($attachment['path'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($attachment['sha256'] ?? ''))
            || ! in_array($attachment['mime_type'] ?? null, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new DomainException('O metadado do anexo da Carta de remoção é inválido.');
        }
    }

    private function assertCfop(string $cfop, string $originUf, string $destinationUf, bool $forEmission): void
    {
        if ($cfop === '') {
            if ($forEmission && $originUf === 'SP' && $destinationUf !== 'SP') {
                throw new DomainException('Para uma rota SP–outra UF, escolha o CFOP de transporte aplicável à operação (prefixo 6).');
            }

            return;
        }

        if (! preg_match('/^\d{4}$/', $cfop)) {
            throw new DomainException('O CFOP deve conter quatro dígitos.');
        }

        if (! in_array($cfop, self::TRANSPORT_CFOPS, true)) {
            throw new DomainException('O CFOP informado não pertence à lista de CFOPs de transporte válidos para CT-e.');
        }

        if ($originUf !== 'SP') {
            $expected = $originUf === $destinationUf ? '5932' : '6932';

            if ($cfop !== $expected) {
                throw new DomainException("Para início em {$originUf} e término em {$destinationUf}, o CFOP deve ser {$expected}.");
            }

            return;
        }

        if ($destinationUf === 'SP' && ($cfop[0] !== '5' || in_array($cfop, ['5932', '6932'], true))) {
            throw new DomainException('Uma prestação iniciada e encerrada em SP exige CFOP iniciado por 5.');
        }

        if ($destinationUf !== 'SP' && ($cfop[0] !== '6' || in_array($cfop, ['5932', '6932'], true))) {
            throw new DomainException('Uma prestação iniciada em SP e encerrada em outra UF exige CFOP de transporte iniciado por 6, aplicável à operação.');
        }
    }

    /** @param array<string, mixed> $city */
    private function assertCity(array $city, string $label): void
    {
        $ibge = (string) ($city['ibge'] ?? '');
        $uf = strtoupper((string) ($city['uf'] ?? ''));

        if (! preg_match('/^\d{7}$/', $ibge)
            || blank($city['name'] ?? null)
            || mb_strlen((string) ($city['name'] ?? '')) > 60
            || ! in_array($uf, self::UFS, true)) {
            throw new DomainException("Informe município IBGE, nome e UF válidos para a {$label}.");
        }
    }

    /** @param array<string, mixed> $party */
    private function assertParty(array $party, string $label): void
    {
        $cnpj = preg_replace('/\D/', '', (string) ($party['cnpj'] ?? '')) ?: '';
        $cpf = preg_replace('/\D/', '', (string) ($party['cpf'] ?? '')) ?: '';

        if (($cnpj === '') === ($cpf === '')
            || ($cnpj !== '' && ! preg_match('/^\d{14}$/', $cnpj))
            || ($cpf !== '' && ! preg_match('/^\d{11}$/', $cpf))) {
            throw new DomainException("Informe exatamente um CNPJ ou CPF válido para {$label}.");
        }

        foreach (['xNome' => 60, 'xLgr' => 60, 'nro' => 60, 'xBairro' => 60, 'xMun' => 60] as $field => $maxLength) {
            $value = trim((string) ($party[$field] ?? ''));

            if ($value === '' || mb_strlen($value) > $maxLength) {
                throw new DomainException("Informe {$field} de {$label} com até {$maxLength} caracteres.");
            }
        }

        if (mb_strlen((string) ($party['xCpl'] ?? '')) > 60
            || ! preg_match('/^\d{7}$/', (string) ($party['cMun'] ?? ''))
            || ! preg_match('/^\d{8}$/', (string) ($party['CEP'] ?? ''))
            || ! in_array(strtoupper((string) ($party['UF'] ?? '')), self::UFS, true)) {
            throw new DomainException("O endereço de {$label} contém município, CEP ou UF inválidos.");
        }

        $ie = mb_strtoupper(trim((string) ($party['ie'] ?? '')));

        if ($ie !== '' && $ie !== 'ISENTO' && ! preg_match('/^\d{1,14}$/', $ie)) {
            throw new DomainException("A inscrição estadual de {$label} deve conter apenas números ou ISENTO.");
        }
    }

    /** @param list<array<string, mixed>> $references */
    private function assertNfeReferences(array $references): void
    {
        $maxReferences = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if (! array_is_list($references) || $references === [] || count($references) > $maxReferences) {
            throw new DomainException("Informe de uma a {$maxReferences} referências de NF-e.");
        }

        $seen = [];

        foreach ($references as $reference) {
            $key = (string) ($reference['access_key'] ?? '');

            if (! preg_match('/^\d{44}$/', $key)
                || substr($key, 20, 2) !== '55'
                || AccessKey::mod11(substr($key, 0, 43)) !== substr($key, -1)
                || isset($seen[$key])) {
                throw new DomainException('O CT-e contém chave de NF-e inválida ou duplicada.');
            }

            $seen[$key] = true;
        }
    }

    /** @param array<string, mixed> $cargo */
    private function assertCargo(array $cargo): void
    {
        if (filled($cargo['weight_kg'] ?? null)) {
            $this->decimal((string) $cargo['weight_kg'], 2, 'carga.weight_kg');
        }

        foreach ([['value', 2]] as [$field, $scale]) {
            if ($this->decimal((string) ($cargo[$field] ?? ''), $scale, "carga.{$field}") <= 0) {
                throw new DomainException("O campo carga.{$field} deve ser maior que zero.");
            }
        }

        if (blank($cargo['predominant_product'] ?? null) || mb_strlen((string) $cargo['predominant_product']) > 60) {
            throw new DomainException('Informe o produto predominante da carga com até 60 caracteres.');
        }

        if (mb_strlen((string) ($cargo['other_category'] ?? '')) > 30) {
            throw new DomainException('Outras características da carga devem ter até 30 caracteres.');
        }

        $measurements = $cargo['measurements'] ?? null;

        if (! is_array($measurements) || ! array_is_list($measurements) || $measurements === []) {
            throw new DomainException('Informe ao menos uma medida real da carga.');
        }

        $hasSupportedCargoMeasure = false;

        foreach ($measurements as $measurement) {
            $unit = (string) ($measurement['unit'] ?? '');
            $measure = trim((string) ($measurement['measure'] ?? ''));
            $quantityScale = $unit === '01' ? 2 : 4;

            if (! in_array($unit, ['00', '01', '02', '03', '04', '05'], true)
                || $measure === ''
                || mb_strlen($measure) > 20
                || $this->decimal((string) ($measurement['quantity'] ?? ''), $quantityScale, 'carga.measurements.quantity') <= 0) {
                throw new DomainException('Uma medida da carga está incompleta ou inválida.');
            }

            $normalizedMeasure = mb_strtoupper($measure);

            if ($normalizedMeasure === 'PESO ESTIMADO' && $unit !== '01') {
                throw new DomainException('PESO ESTIMADO deve usar cUnid=01, em kg.');
            }

            if (in_array($normalizedMeasure, ['VEICULOS', 'UNIDADES'], true) && $unit !== '03') {
                throw new DomainException('VEICULOS e UNIDADES devem usar cUnid=03.');
            }

            if ($unit === '01' && in_array($normalizedMeasure, ['PESO BRUTO', 'PESO ESTIMADO'], true)) {
                $hasSupportedCargoMeasure = true;

                if ($normalizedMeasure === 'PESO BRUTO'
                    && filled($cargo['weight_kg'] ?? null)
                    && $this->decimal((string) $measurement['quantity'], 2, 'carga.measurements.quantity')
                        !== $this->decimal((string) $cargo['weight_kg'], 2, 'carga.weight_kg')) {
                    throw new DomainException('A medida PESO BRUTO deve corresponder ao peso bruto informado.');
                }
            }

            if ($unit === '03' && in_array($normalizedMeasure, ['VEICULOS', 'UNIDADES'], true)) {
                $hasSupportedCargoMeasure = true;
            }
        }

        if (! $hasSupportedCargoMeasure) {
            throw new DomainException('Informe peso estimado em kg ou quantidade transportada de VEICULOS/UNIDADES.');
        }
    }

    /** @param array<string, mixed> $freight */
    private function assertFreight(array $freight): void
    {
        foreach (['total', 'receivable'] as $field) {
            if ($this->decimal((string) ($freight[$field] ?? ''), 2, "freight.{$field}") <= 0) {
                throw new DomainException("O valor freight.{$field} deve ser maior que zero.");
            }
        }

        $components = $freight['components'] ?? [];

        if (! is_array($components) || ! array_is_list($components)) {
            throw new DomainException('Os componentes do frete devem ser uma lista.');
        }

        foreach ($components as $component) {
            $name = trim((string) ($component['name'] ?? ''));

            if ($name === ''
                || mb_strlen($name) > 15
                || $this->decimal((string) ($component['value'] ?? ''), 2, 'freight.components.value') < 0) {
                throw new DomainException('Um componente do frete está incompleto ou inválido.');
            }
        }
    }

    private function decimal(string $value, int $scale, string $field): int
    {
        try {
            return ExactDecimal::parse($value, $scale, 'CT-e avulso', $field);
        } catch (DomainException $exception) {
            throw new DomainException("O campo {$field} deve conter um número válido com até {$scale} casas decimais.", previous: $exception);
        }
    }
}
