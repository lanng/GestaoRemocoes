<?php

namespace App\Enums;

enum CiotLineEnum: string
{
    case VehicleRemoval = 'vehicle_removal';
    case TankAlcohol = 'tank_alcohol';
    case GeneralCargo = 'general_cargo';

    public function label(): string
    {
        return match ($this) {
            self::VehicleRemoval => 'Remoção de veículos',
            self::TankAlcohol => 'Tanque de álcool',
            self::GeneralCargo => 'Avulso / carga geral',
        };
    }

    public function operationalProfile(): self
    {
        return match ($this) {
            self::GeneralCargo => self::VehicleRemoval,
            default => $this,
        };
    }

    /**
     * Natureza operacional confirmada para a API atual: remoção usa o código 1
     * e tanque o código 3. A classificação formal 13/8 não deve ser enviada
     * até que a ANTT confirme que esses códigos estão disponíveis neste endpoint.
     */
    public function naturezaCarga(): string
    {
        return match ($this) {
            self::VehicleRemoval => '0001',
            self::TankAlcohol => '0003',
            self::GeneralCargo => '0001',
        };
    }

    /**
     * Tipo de carga pareado à natureza: 5 = carga geral, 2 = granel líquido.
     */
    public function tipoCarga(): int
    {
        return match ($this) {
            self::VehicleRemoval => 5,
            self::TankAlcohol => 2,
            self::GeneralCargo => 5,
        };
    }

    /**
     * Chave da linha em config/ciot.php (dados bancários por linha).
     */
    public function configKey(): string
    {
        return match ($this) {
            self::VehicleRemoval => 'vehicle_removal',
            self::TankAlcohol => 'tank_alcohol',
            self::GeneralCargo => 'vehicle_removal',
        };
    }
}
