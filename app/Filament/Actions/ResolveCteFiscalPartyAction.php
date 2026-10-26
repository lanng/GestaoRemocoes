<?php

namespace App\Filament\Actions;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers\DocumentsRelationManager;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Services\Cte\CheckCteBatchParties;
use App\Services\Cte\ResolveCteSnapshot;
use App\Services\Cte\SaveCteFiscalAlias;
use DomainException;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Builder;

class ResolveCteFiscalPartyAction
{
    public static function make(string $role): Action
    {
        return Action::make($role === 'sender' ? 'resolveSender' : 'resolveDestination')
            ->label($role === 'sender' ? 'Conciliar remetente' : 'Conciliar destino')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (CteDocument $record): bool => $record->batch?->status === CteEmissionBatchStatusEnum::DRAFT
                && $record->status === CteDocumentStatusEnum::DRAFT)
            ->modalDescription('Confirme a empresa pelo CNPJ. A associação será gravada para os próximos lotes com o mesmo nome de origem.')
            ->form([
                Select::make('company_id')
                    ->label($role === 'sender' ? 'Empresa remetente' : 'Destinatário / tomador')
                    ->options(fn (): array => Company::query()->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (Company $company): array => [$company->id => $company->name.' · '.$company->cnpj.' · '.$company->city.'/'.$company->state])->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state) use ($role): void {
                        if ($role !== 'destination' || filled($get('city_id')) || blank($state)) {
                            return;
                        }
                        $ibge = Company::query()->find($state)?->ibge_code;
                        $set('city_id', City::query()->where('ibge_code', $ibge)->value('id'));
                    })
                    ->createOptionForm(fn (Form $form): Form => CompanyResource::form($form->model(Company::class)->operation('create')))
                    ->createOptionAction(fn (FormAction $action): FormAction => $action->visible(CompanyResource::canCreate()))
                    ->createOptionUsing(function (array $data): int {
                        abort_unless(CompanyResource::canCreate(), 403);

                        return Company::query()->create($data)->id;
                    }),
                Select::make('city_id')
                    ->label('Município efetivo do destino')
                    ->helperText('Confirme a cidade da entrega. O endereço fiscal da empresa permanece no cadastro.')
                    ->visible($role === 'destination')
                    ->required($role === 'destination')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => City::query()
                        ->where(fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%")->orWhere('ibge_code', 'like', "%{$search}%"))
                        ->orderBy('name')->limit(50)->get()
                        ->mapWithKeys(fn (City $city): array => [$city->id => $city->name.'/'.$city->state.' · '.$city->ibge_code])->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($city = City::query()->find($value)) ? $city->name.'/'.$city->state.' · '.$city->ibge_code : null),
            ])
            ->fillForm(function (CteDocument $record) use ($role): array {
                $check = app(CheckCteBatchParties::class)->report($record->batch)[$record->id][$role];
                $cityId = $check['city_id'] ?? null;
                if ($role === 'destination' && $cityId === null) {
                    try {
                        $city = app(ResolveCteSnapshot::class)->resolveCity($check['source'], 'destino');
                        $cityId = City::query()->where('ibge_code', $city['ibge'])->value('id');
                    } catch (DomainException) {
                        $cityId = null;
                    }
                }

                return ['company_id' => $check['company_id'] ?? null, 'city_id' => $cityId];
            })
            ->action(function (CteDocument $record, array $data, DocumentsRelationManager $livewire) use ($role): void {
                abort_unless(CteEmissionBatchResource::canView($record->batch), 403);
                app(SaveCteFiscalAlias::class)->handle(
                    $record->batch, $record, $role, (int) $data['company_id'],
                    isset($data['city_id']) ? (int) $data['city_id'] : null, auth()->user(),
                );
                $livewire->refreshPartyReview();
            })
            ->successNotificationTitle('Associação gravada para este e os próximos lotes.');
    }
}
