<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateGeneralCargoCteDraft
{
    public function __construct(
        private RemoveDraftCteDocument $draftGuard,
        private ValidateGeneralCargoCteSnapshot $snapshotValidator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(CteDocument $document, array $data): CteDocument
    {
        return DB::transaction(function () use ($document, $data): CteDocument {
            $lockedDocument = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($lockedDocument->status !== CteDocumentStatusEnum::DRAFT
                || $lockedDocument->emitter !== 'direct'
                || data_get($lockedDocument->snapshot, 'line') !== 'general_cargo'
                || ! $this->draftGuard->canRemove($lockedDocument)) {
                throw new DomainException('Somente rascunhos avulsos sem número ou evidência fiscal podem ser editados.');
            }

            $snapshot = (array) $lockedDocument->snapshot;
            $cargo = (array) ($data['cargo'] ?? []);
            $measurements = array_values(array_map(
                function (mixed $measurement): array {
                    $unit = trim((string) data_get($measurement, 'unit', ''));

                    return [
                        'unit' => $unit,
                        'measure' => trim((string) data_get($measurement, 'measure', '')),
                        'quantity' => $this->decimal(
                            data_get($measurement, 'quantity', ''),
                            $unit === '01' ? 2 : 4,
                            'quantidade da medida',
                        ),
                    ];
                },
                (array) ($cargo['measurements'] ?? []),
            ));
            $grossWeight = collect($measurements)->first(fn (array $measurement): bool => $measurement['unit'] === '01'
                && mb_strtoupper($measurement['measure']) === 'PESO BRUTO');

            $snapshot['cfop'] = trim((string) ($data['cfop'] ?? ''));
            $snapshot['cargo']['value'] = $this->decimal($cargo['value'] ?? '', 2, 'valor da carga');
            $snapshot['cargo']['predominant_product'] = trim((string) ($cargo['predominant_product'] ?? ''));
            $snapshot['cargo']['other_category'] = trim((string) ($cargo['other_category'] ?? ''));
            $snapshot['cargo']['measurements'] = $measurements;
            $snapshot['cargo_value'] = $snapshot['cargo']['value'];
            unset($snapshot['cargo']['weight_kg'], $snapshot['cargo_weight_kg']);

            if ($grossWeight !== null) {
                $snapshot['cargo']['weight_kg'] = $grossWeight['quantity'];
                $snapshot['cargo_weight_kg'] = $grossWeight['quantity'];
            }

            $this->snapshotValidator->handle($snapshot);

            $lockedDocument->forceFill(['snapshot' => $snapshot])->save();
            $lockedDocument->refresh();

            return $lockedDocument;
        });
    }

    private function decimal(mixed $value, int $scale, string $field): string
    {
        try {
            return ExactDecimal::format(ExactDecimal::parse((string) $value, $scale, 'CT-e avulso', $field), $scale);
        } catch (DomainException $exception) {
            throw new DomainException("O campo {$field} deve conter um número válido com até {$scale} casas decimais.", previous: $exception);
        }
    }
}
