<?php

namespace App\Services\Ciot;

use App\Models\Ciot;
use App\Models\CteDocument;
use App\Services\Cte\ExactDecimal;
use App\Support\PtbrNumeric;
use DomainException;

class ResolveGeneralCargoMdfeCargo
{
    private const MAX_WEIGHT_CENTI_KG = 9_999_999_999;

    /**
     * @param  array<string, mixed>|null  $generalCargo
     * @param  array<string, mixed>  $newCiotData
     * @return array<string, mixed>
     */
    public function prepareNewCiotData(CteDocument $document, ?array $generalCargo, array $newCiotData): array
    {
        $weightInput = $newCiotData['cargo_weight_kg'] ?? null;

        if ($this->isAbsent($weightInput)) {
            $cteSnapshot = (array) $document->snapshot;
            $weight = $this->firstPositiveWeight([
                data_get($cteSnapshot, 'cargo.weight_kg'),
                $cteSnapshot['cargo_weight_kg'] ?? null,
            ], 'peso do CT-e');

            if ($weight === null) {
                $weight = $this->scaledDecimal($generalCargo['weight_kg'] ?? null, 2, 'peso manual');
            }

            if ($weight === null || $weight <= 0) {
                throw new DomainException('Informe o peso real da carga no CT-e ou no campo manual para criar o novo CIOT.');
            }

            $weightInput = $weight;
        } else {
            $weightInput = $this->scaledDecimal($weightInput, 2, 'peso do CIOT');
        }

        if ($weightInput <= 0) {
            throw new DomainException('O peso informado para o novo CIOT deve ser maior que zero.');
        }

        if ($weightInput > self::MAX_WEIGHT_CENTI_KG) {
            throw new DomainException('O peso real da carga geral excede o limite de 99999999.99 kg.');
        }

        $newCiotData['cargo_weight_kg'] = ExactDecimal::format($weightInput, 2);

        $this->handle($document, $generalCargo, newCiotData: $newCiotData);

        return $newCiotData;
    }

    /**
     * @param  array<string, mixed>|null  $generalCargo
     * @param  array<string, mixed>|null  $newCiotData
     * @return array{type: string, ncm: string|null, product: string, cargo_value_cents: int, freight_value_cents: int, weight_centi_kg: int}
     */
    public function handle(
        CteDocument $document,
        ?array $generalCargo,
        ?Ciot $ciot = null,
        ?array $newCiotData = null,
    ): array {
        $cteSnapshot = (array) $document->snapshot;
        $type = trim((string) ($generalCargo['type'] ?? ''));

        if (! preg_match('/^(0[1-9]|1[0-2])$/', $type)) {
            throw new DomainException('Selecione explicitamente um tipo de carga válido para o MDF-e avulso.');
        }

        $ncm = trim((string) ($generalCargo['ncm'] ?? ''));

        if ($ncm !== '') {
            $ncm = preg_replace('/\D/', '', $ncm);

            if (! preg_match('/^(\d{2}|\d{8})$/', (string) $ncm)) {
                throw new DomainException('O NCM do produto predominante deve conter 2 ou 8 dígitos.');
            }
        }

        $product = trim((string) data_get($cteSnapshot, 'cargo.predominant_product', ''));

        if ($product === '') {
            throw new DomainException('O CT-e avulso precisa informar a descrição real do produto predominante.');
        }

        $cargoValue = $this->scaledDecimal(
            data_get($cteSnapshot, 'cargo.value') ?? ($cteSnapshot['cargo_value'] ?? null),
            2,
            'valor real da carga',
        );

        if ($cargoValue === null || $cargoValue <= 0) {
            throw new DomainException('O CT-e avulso precisa informar o valor real e positivo da carga no snapshot.');
        }

        $freightValue = $this->scaledDecimal($cteSnapshot['value'] ?? null, 2, 'frete do CT-e');

        if ($freightValue === null || $freightValue <= 0) {
            throw new DomainException('O CT-e avulso precisa informar um frete positivo com até duas casas decimais.');
        }

        $ciotFreight = $newCiotData !== null
            ? $this->scaledDecimal($newCiotData['freight_value'] ?? null, 2, 'frete do novo CIOT')
            : $this->persistedFreightCents($ciot);

        if ($ciotFreight === null || $ciotFreight <= 0 || $ciotFreight !== $freightValue) {
            throw new DomainException('O frete do CIOT deve ser positivo e corresponder exatamente ao frete do CT-e avulso.');
        }

        $manualWeightInput = $generalCargo['weight_kg'] ?? null;
        $manualWeight = $this->scaledDecimal($manualWeightInput, 2, 'peso manual');

        if (! $this->isAbsent($manualWeightInput) && $manualWeight === 0) {
            throw new DomainException('O peso manual informado deve ser maior que zero.');
        }

        $ciotWeightInput = $newCiotData !== null
            ? ($newCiotData['cargo_weight_kg'] ?? null)
            : $this->persistedWeight($ciot);

        if ($newCiotData !== null && ! $this->isAbsent($ciotWeightInput)
            && $this->scaledDecimal($ciotWeightInput, 2, 'peso do CIOT') === 0) {
            throw new DomainException('O peso informado para o novo CIOT deve ser maior que zero.');
        }

        if ($newCiotData === null) {
            $persistedWeight = $this->scaledDecimal($ciotWeightInput, 2, 'peso do CIOT');

            if ($persistedWeight === null || $persistedWeight <= 0) {
                throw new DomainException('O CIOT emitido não possui peso real positivo e não pode ser alterado; crie um novo CIOT compatível.');
            }
        }

        $weightCandidates = [];

        foreach ([
            ['value' => data_get($cteSnapshot, 'cargo.weight_kg'), 'label' => 'peso do CT-e'],
            ['value' => $cteSnapshot['cargo_weight_kg'] ?? null, 'label' => 'peso do CT-e'],
            ['value' => $ciotWeightInput, 'label' => 'peso do CIOT'],
            ['value' => $manualWeightInput, 'label' => 'peso manual'],
        ] as $candidate) {
            $weight = $this->scaledDecimal($candidate['value'], 2, $candidate['label']);

            if ($weight === null || $weight === 0) {
                continue;
            }

            if ($weight > self::MAX_WEIGHT_CENTI_KG) {
                throw new DomainException('O peso real da carga geral excede o limite de 99999999.99 kg.');
            }

            $weightCandidates[] = $weight;
        }

        if ($weightCandidates === []) {
            throw new DomainException('Informe o peso real da carga geral no CT-e, no CIOT ou manualmente.');
        }

        if (count(array_unique($weightCandidates)) !== 1) {
            throw new DomainException('Os pesos informados no CT-e, CIOT e MDF-e avulso devem corresponder exatamente.');
        }

        $resolvedWeight = $weightCandidates[0];
        $ciotWeight = $this->scaledDecimal(
            $ciotWeightInput,
            2,
            'peso do CIOT',
        );

        if ($ciotWeight === null || $ciotWeight <= 0 || $ciotWeight !== $resolvedWeight) {
            throw new DomainException('O peso final do CIOT deve ser positivo e corresponder exatamente ao peso do MDF-e avulso.');
        }

        return [
            'type' => $type,
            'ncm' => $ncm === '' ? null : $ncm,
            'product' => $product,
            'cargo_value_cents' => $cargoValue,
            'freight_value_cents' => $freightValue,
            'weight_centi_kg' => $resolvedWeight,
        ];
    }

    private function scaledDecimal(mixed $value, int $scale, string $field): ?int
    {
        if (blank($value)) {
            return null;
        }

        $normalized = $this->normalizeInput($value);

        if ($normalized === null) {
            throw $this->precisionException($field);
        }

        try {
            return ExactDecimal::parse($normalized, $scale, 'MDF-e avulso', $field);
        } catch (DomainException $exception) {
            $message = str_contains($field, 'peso')
                ? 'O peso real da carga geral deve conter até duas casas decimais.'
                : "O {$field} deve conter até duas casas decimais válidas.";

            throw new DomainException($message, previous: $exception);
        }
    }

    private function normalizeInput(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim(str_replace(["\u{00A0}", ' '], '', $value));

        if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return $value;
        }

        return PtbrNumeric::normalize($value);
    }

    /** @param array<int, mixed> $weights */
    private function firstPositiveWeight(array $weights, string $field): ?int
    {
        foreach ($weights as $candidate) {
            $weight = $this->scaledDecimal($candidate, 2, $field);

            if ($weight === null || $weight === 0) {
                continue;
            }

            if ($weight > self::MAX_WEIGHT_CENTI_KG) {
                throw new DomainException('O peso real da carga geral excede o limite de 99999999.99 kg.');
            }

            return $weight;
        }

        return null;
    }

    private function isAbsent(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function persistedFreightCents(?Ciot $ciot): ?int
    {
        if ($ciot === null) {
            return null;
        }

        $value = $ciot->getRawOriginal('freight_value_cents');

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function persistedWeight(?Ciot $ciot): mixed
    {
        return $ciot?->getRawOriginal('cargo_weight_kg');
    }

    private function precisionException(string $field): DomainException
    {
        return str_contains($field, 'peso')
            ? new DomainException('O peso real da carga geral deve conter até duas casas decimais.')
            : new DomainException("O {$field} deve conter até duas casas decimais válidas.");
    }
}
