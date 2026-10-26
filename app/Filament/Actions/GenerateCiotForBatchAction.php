<?php

namespace App\Filament\Actions;

use App\Enums\CiotStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource\Pages\ViewCteEmissionBatch;
use App\Filament\Support\CiotEmissionNotice;
use App\Models\City;
use App\Models\Company;
use App\Models\CteEmissionBatch;
use App\Services\Ciot\CityDistanceCalculator;
use App\Services\Ciot\CreateCiotForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Ciot\PrefillCiotFromBatch;
use App\Support\PtbrNumeric;
use App\Support\PtbrNumericRule;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FormsAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;

class GenerateCiotForBatchAction
{
    public static function make(CteEmissionBatch $batch): Action
    {
        $prefill = app(PrefillCiotFromBatch::class)->handle($batch);
        $form = $prefill['form'];

        return Action::make('generateCiot')
            ->label('Gerar CIOT')
            ->icon('heroicon-m-paper-airplane')
            ->color('success')
            ->visible(fn (): bool => in_array(($batch->fresh()->status ?? $batch->status), [
                CteEmissionBatchStatusEnum::APPROVED,
                CteEmissionBatchStatusEnum::PROCESSING,
                CteEmissionBatchStatusEnum::COMPLETED,
                CteEmissionBatchStatusEnum::COMPLETED_WITH_ERRORS,
            ], true) && ! $batch->ciots()
                ->where('status', '!=', CiotStatusEnum::CANCELED->value)
                ->exists())
            ->modalHeading('Gerar CIOT da viagem')
            ->modalSubmitActionLabel('Gerar e emitir')
            ->modalDescription(new \Illuminate\Support\HtmlString($prefill['ranking'] !== ''
                ? 'Maior rota pré-selecionada. Distâncias calculadas:<br>'.nl2br(e($prefill['ranking']))
                : 'Sem distâncias calculáveis — revise origem e destino.'))
            ->form(static::form($batch, $form, $prefill))
            ->action(function (array $data, ViewCteEmissionBatch $livewire) use ($batch): void {
                static::submit($batch, $data);
                $livewire->refreshBatch();
            });
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array{form: array<string, mixed>, ranking: string, warnings: list<string>, origins: \Illuminate\Support\Collection, patios: \Illuminate\Support\Collection}  $prefill
     * @return list<\Filament\Forms\Components\Component>
     */
    protected static function form(CteEmissionBatch $batch, array $form, array $prefill): array
    {
        $payers = Company::query()->where('is_active', true)->orderBy('name')->get();

        $originOptions = $prefill['origins']
            ->mapWithKeys(fn (City $city): array => [$city->ibge_code => "{$city->name} - {$city->state}"])
            ->all();

        $payerOptions = $payers
            ->mapWithKeys(fn (Company $payer): array => [$payer->id => sprintf('%s — %s/%s', $payer->name, $payer->city, $payer->state)])
            ->all();

        $destination = $form['destination'] ?? [];

        return [
            Hidden::make('status')->default(CiotStatusEnum::DRAFT->value),
            Hidden::make('line')->default($form['line']),

            Placeholder::make('avisos')
                ->content(implode("\n", $prefill['warnings']))
                ->visible(fn (): bool => $prefill['warnings'] !== []),

            Select::make('operation_type')
                ->label('Tipo de operação')
                ->options([
                    'lotation' => 'Lotação (um pátio)',
                    'fractioned' => 'Fracionada (mais de um pátio)',
                ])
                ->default($form['operation_type'])
                ->live()
                ->required(),

            Select::make('payer_id')
                ->label('Contratante (pátio pagante)')
                ->options($payerOptions)
                ->searchable()
                ->live()
                ->default($form['payer_id'])
                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                    $cnpj = Company::query()->find($state)?->cnpj;

                    if (filled($cnpj)) {
                        $set('additional_payers', \App\Filament\Resources\CiotResource::withoutPayerCnpjs($get('additional_payers') ?? [], [$cnpj]));
                    }
                })
                ->required(),

            CheckboxList::make('additional_payers')
                ->label('Contratantes adicionais (demais pátios da viagem)')
                ->options(fn (Get $get): array => \App\Filament\Resources\CiotResource::additionalPayerOptions($get('payer_id')))
                ->columns(2)
                ->live()
                ->default($form['additional_payers'] ?? [])
                ->nestedRecursiveRules([fn (Get $get): string => \App\Filament\Resources\CiotResource::additionalPayerRule($get)])
                ->validationMessages(['not_in' => 'O pátio pagante não pode repetir nos contratantes adicionais (regra B119 da ANTT).'])
                ->visible(fn (Get $get): bool => $get('operation_type') === 'fractioned'),

            Select::make('delivery_payer_id')
                ->label('Destinatário (pátio final da entrega)')
                ->options($payerOptions)
                ->searchable()
                ->live()
                ->default($form['delivery_payer_id'])
                ->afterStateUpdated(function (Get $get, ?string $state, callable $set): void {
                    $payer = Company::query()->find($state);

                    if ($payer === null) {
                        return;
                    }

                    $set('destination.cidade', $payer->city);
                    $set('destination.uf', $payer->state);
                    $set('destination.ibge', $payer->ibge_code);

                    if (filled($payer->zipcode)) {
                        $set('destination.cep', $payer->zipcode);
                    }

                    static::recalculateDistance($get, $set);
                })
                ->required(),

            Fieldset::make('Rota')
                ->columns(2)
                ->schema([
                    Select::make('origin.ibge')
                        ->label('Cidade de origem')
                        ->options($originOptions)
                        ->default($form['origin.ibge'])
                        ->live()
                        ->afterStateUpdated(function (Get $get, ?string $state, callable $set): void {
                            $city = City::query()->where('ibge_code', $state)->first();

                            if ($city !== null) {
                                $set('origin.cidade', $city->name);
                                $set('origin.uf', $city->state);
                            }

                            static::recalculateDistance($get, $set);
                        })
                        ->required(),
                    TextInput::make('origin.cep')
                        ->label('CEP de origem (usado no MDF-e)')
                        ->mask('99999-999')
                        ->maxLength(9)
                        ->live(onBlur: true),
                    Hidden::make('origin.cidade')->default($form['origin.cidade']),
                    Hidden::make('origin.uf')->default($form['origin.uf']),
                    TextInput::make('destination.cep')
                        ->label('CEP de destino (usado no MDF-e)')
                        ->mask('99999-999')
                        ->maxLength(9)
                        ->default($destination['cep'] ?? null),
                    Hidden::make('destination.ibge')->default($destination['ibge'] ?? null),
                    Hidden::make('destination.cidade')->default($destination['cidade'] ?? null),
                    Hidden::make('destination.uf')->default($destination['uf'] ?? null),
                    Placeholder::make('destino_display')
                        ->label('Destino')
                        ->content(fn (Get $get): string => trim(sprintf(
                            '%s/%s',
                            $get('destination.cidade') ?: '—',
                            $get('destination.uf') ?? '',
                        ), '/')),
                    TextInput::make('distance_km')
                        ->label('Distância (km)')
                        ->inputMode('decimal')
                        ->formatStateUsing(fn ($state) => PtbrNumeric::format($state))
                        ->rule(new PtbrNumericRule(min: 0.01))
                        ->default($form['distance_km'])
                        ->required()
                        ->dehydrateStateUsing(fn ($state) => PtbrNumeric::normalize($state))
                        ->suffixAction(
                            FormsAction::make('calcularDistancia')
                                ->icon('heroicon-m-calculator')
                                ->label('Calcular distância')
                                ->color('gray')
                                ->action(function (Get $get, callable $set): void {
                                    static::recalculateDistance($get, $set, force: true);
                                }),
                        ),
                ]),

            Fieldset::make('Carga')
                ->columns(2)
                ->schema([
                    TextInput::make('freight_value')
                        ->label('Valor do frete (total da viagem)')
                        ->prefix('R$')
                        ->inputMode('decimal')
                        ->formatStateUsing(fn ($state) => PtbrNumeric::format($state))
                        ->rule(new PtbrNumericRule(min: 0.01))
                        ->default($form['freight_value'])
                        ->required()
                        ->dehydrateStateUsing(fn ($state) => PtbrNumeric::normalize($state)),
                    TextInput::make('cargo_weight_kg')
                        ->label('Peso da carga (kg)')
                        ->inputMode('decimal')
                        ->formatStateUsing(fn ($state) => PtbrNumeric::format($state))
                        ->rule(new PtbrNumericRule(min: 0))
                        ->default($form['cargo_weight_kg'])
                        ->required()
                        ->dehydrateStateUsing(fn ($state) => PtbrNumeric::normalize($state)),
                ]),

            CheckboxList::make('vehicle_ids')
                ->label('Veículos (composição da linha de remoção)')
                ->options(fn (): array => app(PrefillCiotFromBatch::class)->lineVehicles()
                    ->mapWithKeys(fn ($vehicle): array => [$vehicle->id => sprintf(
                        '%s — %s, %d eixo(s)',
                        $vehicle->plate,
                        $vehicle->type,
                        $vehicle->axles,
                    )])
                    ->all())
                ->columns(2)
                ->default($form['vehicle_ids'])
                ->required()
                ->columnSpanFull(),

            Fieldset::make('Viagem')
                ->columns(2)
                ->schema([
                    DatePicker::make('travel_start_at')
                        ->label('Início da viagem')
                        ->default($form['travel_start_at'])
                        ->minDate(today())
                        ->required(),
                    DatePicker::make('travel_end_at')
                        ->label('Fim da viagem')
                        ->default($form['travel_end_at'])
                        ->minDate(today())
                        ->required(),
                ]),

            Fieldset::make('Indicadores operacionais')
                ->columns(3)
                ->visible(fn (Get $get): bool => $get('operation_type') === 'lotation')
                ->schema([
                    Toggle::make('indicators.ind_alto_desempenho')->label('Alto desempenho')->default(true),
                    Toggle::make('indicators.ind_retorno_vazio')->label('Retorno vazio')->default(true),
                    Toggle::make('indicators.composicao_veicular')->label('Composição veicular')->default(true),
                ]),
        ];
    }

    protected static function recalculateDistance(Get $get, callable $set, bool $force = false): void
    {
        $origin = City::query()->where('ibge_code', $get('origin.ibge'))->first();
        $destination = City::query()->where('ibge_code', $get('destination.ibge'))->first();

        if ($origin === null || $destination === null
            || ! $origin->hasCoordinates() || ! $destination->hasCoordinates()) {
            return;
        }

        if (! $force && filled($get('distance_km'))) {
            return;
        }

        $km = app(CityDistanceCalculator::class)->calculate($origin, $destination);

        if ($km !== null) {
            $set('distance_km', PtbrNumeric::format($km));
        }
    }

    protected static function submit(CteEmissionBatch $batch, array $data): void
    {
        try {
            $ciot = app(CreateCiotForBatch::class)->handle($batch, $data);
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Este lote já possui um CIOT ativo')
                ->body('Cancele o CIOT existente para emitir outro para esta viagem.')
                ->warning()
                ->send();

            return;
        }

        $result = app(EmitCiotDeclaration::class)->emit($ciot);

        (new CiotEmissionNotice)->send($result);
    }
}
