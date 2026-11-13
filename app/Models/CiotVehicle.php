<?php

namespace App\Models;

use App\Enums\CiotLineEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CiotVehicle extends Model
{
    /** @use HasFactory<\Database\Factories\CiotVehicleFactory> */
    use HasFactory;

    public const TYPE_AUTOMOTOR = 'automotor';

    public const TYPE_TRAILER = 'reboque';

    protected $fillable = [
        'plate', 'rntrc', 'axles', 'type', 'line', 'description', 'is_active',
        'renavam', 'tare_kg', 'capacity_kg', 'capacity_m3', 'type_rod', 'body_type', 'uf',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'axles' => 'integer',
            'tare_kg' => 'integer',
            'capacity_kg' => 'integer',
            'capacity_m3' => 'integer',
        ];
    }

    public function isAutomotor(): bool
    {
        return $this->type === self::TYPE_AUTOMOTOR;
    }

    /**
     * Linha para a qual o veículo é composto (nulo = disponível para todas).
     */
    public function servesLine(string $line): bool
    {
        $requestedLine = CiotLineEnum::tryFrom($line)?->operationalProfile()->value ?? $line;

        return $this->is_active && ($this->line === null || $this->line === $requestedLine);
    }

    /**
     * Snapshot no formato enviado no payload `Veiculos[]` do CIOT.
     *
     * @return array{placa: string, rntrc: ?string, eixos: int, tipo: string}
     */
    public function snapshot(): array
    {
        return [
            'placa' => $this->plate,
            'rntrc' => $this->rntrc,
            'eixos' => $this->axles,
            'tipo' => $this->type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mdfeSnapshot(): array
    {
        return [
            'plate' => $this->plate,
            'rntrc' => $this->rntrc,
            'renavam' => $this->renavam,
            'tare_kg' => $this->tare_kg,
            'capacity_kg' => $this->capacity_kg,
            'capacity_m3' => $this->capacity_m3,
            'type_rod' => $this->type_rod,
            'body_type' => $this->body_type,
            'uf' => $this->uf,
            'type' => $this->type,
        ];
    }
}
