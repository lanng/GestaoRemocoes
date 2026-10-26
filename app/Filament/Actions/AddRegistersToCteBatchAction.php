<?php

namespace App\Filament\Actions;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Services\Cte\CreateCteEmissionBatch;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AddRegistersToCteBatchAction
{
    public static function make(CteEmissionBatch $batch): Action
    {
        return Action::make('addRegisters')
            ->label('Incluir registros')
            ->icon('heroicon-m-plus')
            ->authorize(fn (): bool => CteEmissionBatchResource::canEdit($batch))
            ->visible(fn (): bool => CteEmissionBatchResource::canEdit($batch) && in_array($batch->fresh()->status, [
                CteEmissionBatchStatusEnum::DRAFT,
                CteEmissionBatchStatusEnum::COMPLETED,
                CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            ], true))
            ->modalHeading('Incluir registros no lote')
            ->modalSubmitActionLabel('Incluir registros')
            ->form([
                Select::make('register_ids')
                    ->label('Registros (ID, placa ou código do veículo)')
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchRegisters($batch, $search))
                    ->getOptionLabelsUsing(fn (array $values): array => Register::query()
                        ->whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(fn (Register $register): array => [$register->id => self::registerLabel($register)])
                        ->all()),
            ])
            ->action(function (array $data, ViewCteEmissionBatch $livewire) use ($batch): void {
                abort_unless(CteEmissionBatchResource::canEdit($batch), 403);

                try {
                    $ids = $data['register_ids'] ?? [];
                    if (! is_array($ids)
                        || collect($ids)->contains(fn (mixed $id): bool => (! is_int($id) && ! is_string($id))
                            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)
                        || count($ids) !== count(array_unique(array_map('intval', $ids)))) {
                        throw ValidationException::withMessages(['register_ids' => 'Selecione registros sem duplicação.']);
                    }

                    $registers = Register::query()->whereIn('id', $ids)->get();
                    if ($registers->count() !== count($ids)) {
                        throw ValidationException::withMessages(['register_ids' => 'Um ou mais registros não foram encontrados.']);
                    }

                    app(CreateCteEmissionBatch::class)->addRegistersToBatch($batch, $registers);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Não foi possível incluir os registros')
                        ->body(implode("\n", array_merge(...array_values($exception->errors()))))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Registros incluídos para revisão')
                    ->body('Os novos CT-es estão em rascunho e não foram enviados para emissão.')
                    ->success()
                    ->send();

                $livewire->refreshBatch();
            });
    }

    /** @return array<int, string> */
    private static function searchRegisters(CteEmissionBatch $batch, string $search): array
    {
        $registers = Register::query()
            ->whereDoesntHave('cteDocuments', fn (Builder $query): Builder => $query->whereIn('status', [
                CteDocumentStatusEnum::DRAFT->value,
                CteDocumentStatusEnum::QUEUED->value,
                CteDocumentStatusEnum::CLAIMED->value,
                CteDocumentStatusEnum::FILLING->value,
                CteDocumentStatusEnum::VALIDATING->value,
                CteDocumentStatusEnum::READY_TO_AUTHORIZE->value,
                CteDocumentStatusEnum::AUTHORIZING->value,
                CteDocumentStatusEnum::WAITING_FOR_XML->value,
                CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value,
                CteDocumentStatusEnum::AUTHORIZED->value,
            ]))
            ->whereDoesntHave('cteDocuments', fn (Builder $query): Builder => $query
                ->where('status', CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value)
                ->where(fn (Builder $query): Builder => $query->whereNotNull('access_key')->orWhereNotNull('payload_xml'))
                ->where(fn (Builder $query): Builder => $query->whereNull('fiscal_status')
                    ->orWhere('fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value))
                ->where(fn (Builder $query): Builder => $query->whereNull('fiscal_status_code')->orWhere('fiscal_status_code', '!=', '101')))
            ->whereDoesntHave('cteDocuments.batch', fn (Builder $query): Builder => $query->whereKey($batch->id))
            ->where(function (Builder $query) use ($search): void {
                $query->whereLike('vehicle_plate', "%{$search}%")
                    ->orWhereLike('vehicle_id', "%{$search}%")
                    ->orWhereLike('vehicle_model', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $registers->mapWithKeys(fn (Register $register): array => [$register->id => self::registerLabel($register)])->all();
    }

    private static function registerLabel(Register $register): string
    {
        return sprintf('#%d · %s · %s', $register->id, $register->vehicle_plate, $register->vehicle_id);
    }
}
