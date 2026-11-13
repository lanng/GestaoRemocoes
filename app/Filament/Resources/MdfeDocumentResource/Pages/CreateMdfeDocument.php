<?php

namespace App\Filament\Resources\MdfeDocumentResource\Pages;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Models\Ciot;
use App\Models\City;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDriver;
use App\Services\Ciot\PrefillCiotFromDocuments;
use App\Services\Ciot\PrepareMdfeDocument;
use App\Support\PtbrNumeric;
use App\Support\PtbrNumericRule;
use DomainException;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CreateMdfeDocument extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = MdfeDocumentResource::class;

    protected static string $view = 'filament.resources.mdfe-document-resource.pages.create-mdfe-document';

    protected static ?string $title = 'Preparar MDF-e';

    public ?array $data = [];

    #[Locked]
    public ?int $batchId = null;

    /** @var list<string> */
    public array $prefillWarnings = [];

    public string $routeRanking = '';

    public function mount(int|string|null $record = null): void
    {
        abort_unless(auth()->check() && MdfeDocumentResource::canCreate(), 403);

        $requestedBatchId = request()->query('batch');
        $this->batchId = is_numeric($requestedBatchId) && (int) $requestedBatchId > 0
            ? (int) $requestedBatchId
            : null;

        $this->form->fill([
            'line' => null,
            'cte_document_ids' => [],
            'ciot_mode' => null,
            'ciot_id' => null,
            'driver_id' => null,
            'new_ciot' => [],
            'endorsements' => [[
                'number' => trim((string) config('dfe.mdfe.default_endorsement', '')),
            ]],
            'route_ufs' => [],
            ...$this->batchPrefillState(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('CT-es autorizados')
                    ->description('Escolha a modalidade e selecione os documentos na ordem em que devem aparecer no MDF-e. Somente CT-es internos do fluxo controlado e ainda disponíveis são oferecidos.')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('batch_context')
                            ->label('Contexto')
                            ->content(fn (): string => $this->contextBatch() === null
                                ? 'Seleção independente, sem lote de origem.'
                                : 'Lote de origem #'.$this->contextBatch()->id.'; CT-es elegíveis do lote aparecem pré-selecionados.')
                            ->columnSpanFull(),
                        Select::make('line')
                            ->label('Modalidade')
                            ->options(self::lineOptions())
                            ->placeholder('Selecione a modalidade dos CT-es')
                            ->live()
                            ->required()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $set('cte_document_ids', []);
                                $set('ciot_id', null);
                                $set('general_cargo', []);
                                $line = CiotLineEnum::tryFrom((string) $state);
                                $set('driver_id', $line === null ? null : $this->defaultDriverId($line));
                                $set('new_ciot', $line === null ? [] : $this->defaultNewCiotData($line));
                                $this->prefillWarnings = [];
                                $this->routeRanking = '';
                            }),
                        Select::make('cte_document_ids')
                            ->label('CT-es do MDF-e')
                            ->placeholder('Pesquise por número, série ou chave')
                            ->multiple()
                            ->maxItems(fn (Get $get): ?int => in_array($get('line'), [
                                CiotLineEnum::TankAlcohol->value,
                                CiotLineEnum::GeneralCargo->value,
                            ], true) ? 1 : null)
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->options(fn (Get $get): array => $this->cteInitialOptions($get('line')))
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->cteSearchResults(
                                $search,
                                $get('line'),
                            ))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->cteOptionLabels($values))
                            ->afterStateUpdated(function (Get $get, Set $set, ?array $state): void {
                                $this->updateSelection($set, $get('line'), $state ?? [], (array) $get('new_ciot'));
                            })
                            ->columnSpanFull(),
                        Placeholder::make('selection_summary')
                            ->label('Resumo da seleção')
                            ->content(fn (Get $get): HtmlString|string => $this->selectionSummary(
                                $get('cte_document_ids'),
                                $get('line'),
                            ))
                            ->columnSpanFull(),
                    ]),
                Section::make('CIOT da operação')
                    ->description('Use um CIOT já emitido e aberto ou escolha Criar e emitir para aguardar a confirmação da ANTT.')
                    ->columns(2)
                    ->schema([
                        Select::make('ciot_mode')
                            ->label('CIOT')
                            ->placeholder('Escolha explicitamente')
                            ->options(fn (): array => $this->ciotModeOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('ciot_id', null);
                            })
                            ->columnSpanFull(),
                        Select::make('ciot_id')
                            ->label('CIOT emitido')
                            ->placeholder('Selecione o CIOT desta operação')
                            ->options(fn (Get $get): array => $this->ciotOptions($get('line')))
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('ciot_mode') === 'existing')
                            ->visible(fn (Get $get): bool => $get('ciot_mode') === 'existing')
                            ->columnSpanFull(),
                        Placeholder::make('ciot_prefill_notice')
                            ->label('Sugestões para o novo CIOT')
                            ->content(fn (): HtmlString|string => $this->prefillNotice())
                            ->visible(fn (Get $get): bool => $get('ciot_mode') === 'new' && $get('line') !== null)
                            ->columnSpanFull(),
                        \Filament\Forms\Components\Group::make()
                            ->schema(CiotResource::formSchema(
                                lineReadOnly: true,
                                allowGeneralCargoWeightFallback: true,
                            ))
                            ->statePath('new_ciot')
                            ->visible(fn (Get $get): bool => $get('ciot_mode') === 'new' && $get('line') !== null)
                            ->columnSpanFull(),
                    ]),
                Section::make('Dados do MDF-e')
                    ->columns(2)
                    ->schema([
                        Select::make('general_cargo.type')
                            ->label('Tipo da carga predominante')
                            ->placeholder('Selecione o tipo conforme a classificação fiscal')
                            ->options(self::generalCargoTypeOptions())
                            ->required(fn (Get $get): bool => $get('line') === CiotLineEnum::GeneralCargo->value)
                            ->visible(fn (Get $get): bool => $get('line') === CiotLineEnum::GeneralCargo->value),
                        TextInput::make('general_cargo.ncm')
                            ->label('NCM do produto predominante (opcional)')
                            ->maxLength(8)
                            ->helperText('Informe 2 ou 8 dígitos, se disponível no CT-e.')
                            ->visible(fn (Get $get): bool => $get('line') === CiotLineEnum::GeneralCargo->value),
                        TextInput::make('general_cargo.weight_kg')
                            ->label('Peso real manual para o novo CIOT (kg)')
                            ->helperText('Use somente se necessário; deve corresponder ao CT-e e será gravado no novo CIOT. Um CIOT emitido não pode ser alterado.')
                            ->inputMode('decimal')
                            ->formatStateUsing(fn ($state): ?string => PtbrNumeric::format($state))
                            ->rule(new PtbrNumericRule(min: 0.01))
                            ->rules(fn (Get $get): array => $get('line') === CiotLineEnum::GeneralCargo->value
                                && $get('ciot_mode') === 'new'
                                ? [CiotResource::exactGeneralCargoDecimalRule('peso manual', mustBePositive: true)]
                                : [])
                            ->dehydrateStateUsing(fn ($state): ?string => PtbrNumeric::normalize($state))
                            ->visible(fn (Get $get): bool => $get('line') === CiotLineEnum::GeneralCargo->value
                                && $get('ciot_mode') === 'new'),
                        Repeater::make('endorsements')
                            ->label('Averbações desta operação')
                            ->helperText(filled(config('dfe.mdfe.default_endorsement'))
                                ? 'A averbação padrão está preenchida. Você pode ajustá-la para esta operação.'
                                : 'Não há valor padrão; informe ao menos uma averbação para continuar.')
                            ->schema([
                                TextInput::make('number')
                                    ->label('Número da averbação')
                                    ->maxLength(40)
                                    ->required(),
                            ])
                            ->minItems(1)
                            ->required()
                            ->columnSpanFull(),
                        Select::make('driver_id')
                            ->label('Motorista do MDF-e')
                            ->options(fn (Get $get): array => MdfeDriver::query()
                                ->where('line', CiotLineEnum::tryFrom((string) $get('line'))?->operationalProfile()->value)
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->visible(fn (Get $get): bool => CiotLineEnum::tryFrom((string) $get('line')) !== null)
                            ->helperText('A escolha vale apenas para este MDF-e e não altera o motorista fixo do cadastro.')
                            ->columnSpanFull(),
                        Select::make('route_ufs')
                            ->label('UFs de percurso, se houver')
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => self::stateOptions())
                            ->helperText('A origem e o destino são incluídos automaticamente.')
                            ->columnSpanFull(),
                        Toggle::make('homologation_fixture')
                            ->label('Usar dados sintéticos de seguro para homologação')
                            ->helperText('Esses dados ficam somente no snapshot desta operação.')
                            ->visible(fn (): bool => (string) config('dfe.tp_amb') === '2'),
                        TextInput::make('fixture_insurer_name')
                            ->label('Nome da seguradora de teste')
                            ->maxLength(30)
                            ->required(fn (Get $get): bool => (bool) $get('homologation_fixture'))
                            ->visible(fn (Get $get): bool => (string) config('dfe.tp_amb') === '2' && (bool) $get('homologation_fixture')),
                        TextInput::make('fixture_insurer_cnpj')
                            ->label('CNPJ da seguradora de teste')
                            ->maxLength(14)
                            ->required(fn (Get $get): bool => (bool) $get('homologation_fixture'))
                            ->visible(fn (Get $get): bool => (string) config('dfe.tp_amb') === '2' && (bool) $get('homologation_fixture')),
                        TextInput::make('fixture_policy_number')
                            ->label('Apólice de teste')
                            ->maxLength(20)
                            ->required(fn (Get $get): bool => (bool) $get('homologation_fixture'))
                            ->visible(fn (Get $get): bool => (string) config('dfe.tp_amb') === '2' && (bool) $get('homologation_fixture')),
                    ]),
            ])
            ->statePath('data');
    }

    public function prepare(): void
    {
        abort_unless(auth()->check() && MdfeDocumentResource::canCreate(), 403);

        try {
            $result = app(PrepareMdfeDocument::class)->handle(
                $this->form->getState(),
                $this->contextBatch(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'data.cte_document_ids' => $exception->getMessage(),
            ]);
        } catch (ValidationException $exception) {
            if (collect(array_keys($exception->errors()))->contains(fn (string $key): bool => str_starts_with($key, 'data.'))) {
                throw $exception;
            }

            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn (array $messages, string $key): array => ['data.'.$key => $messages],
            )->all());
        }

        if ($result->ciotEmissionResult !== null) {
            (new CiotEmissionNotice)->send($result->ciotEmissionResult);
        }

        if ($result->mdfe !== null) {
            Notification::make()
                ->title('MDF-e enviado à fila de emissão direta.')
                ->success()
                ->send();

            $this->redirect(MdfeDocumentResource::getUrl('view', ['record' => $result->mdfe]));

            return;
        }

        if ($result->ciotEmissionResult?->isIssued()) {
            Notification::make()
                ->title('CIOT emitido; MDF-e não foi enfileirado.')
                ->body($result->message ?? 'Revise os CT-es e reutilize este CIOT ao retomar a preparação.')
                ->warning()
                ->send();
        }

        $this->redirect(CiotResource::getUrl('view', ['record' => $result->ciot]));
    }

    public function isEditing(): bool
    {
        return false;
    }

    public function getBackUrl(): string
    {
        return MdfeDocumentResource::getUrl('index');
    }

    /** @return array<string, string> */
    private static function lineOptions(): array
    {
        return collect(CiotLineEnum::cases())
            ->mapWithKeys(fn (CiotLineEnum $line): array => [$line->value => $line->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function generalCargoTypeOptions(): array
    {
        return [
            '01' => 'Granel sólido',
            '02' => 'Granel líquido',
            '03' => 'Frigorificada',
            '04' => 'Conteinerizada',
            '05' => 'Carga geral',
            '06' => 'Neogranel',
            '07' => 'Perigosa (granel sólido)',
            '08' => 'Perigosa (granel líquido)',
            '09' => 'Perigosa (carga frigorificada)',
            '10' => 'Perigosa (conteinerizada)',
            '11' => 'Perigosa (carga geral)',
            '12' => 'Granel pressurizada',
        ];
    }

    /** @return array<string, string> */
    protected function ciotModeOptions(): array
    {
        $options = [
            'existing' => 'Usar um CIOT emitido e aberto',
            'new' => 'Criar e emitir um novo CIOT',
        ];

        return $options;
    }

    /** @return array<string, string> */
    private static function stateOptions(): array
    {
        return City::query()
            ->select('state')
            ->distinct()
            ->orderBy('state')
            ->pluck('state', 'state')
            ->all();
    }

    /** @return array<int|string, string> */
    protected function cteSearchResults(string $search, mixed $lineValue): array
    {
        $line = CiotLineEnum::tryFrom((string) $lineValue);

        if ($line === null) {
            return [];
        }

        return app(PrepareMdfeDocument::class)
            ->eligibleCteDocuments($line, $search, $this->batchId)
            ->mapWithKeys(fn (CteDocument $document): array => [
                $document->id => app(PrepareMdfeDocument::class)->cteOptionLabel($document),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    protected function cteInitialOptions(mixed $lineValue): array
    {
        $line = CiotLineEnum::tryFrom((string) $lineValue);

        if ($line === null) {
            return [];
        }

        return app(PrepareMdfeDocument::class)
            ->eligibleCteDocuments($line, limit: 5, batchId: $this->batchId)
            ->mapWithKeys(fn (CteDocument $document): array => [
                $document->id => app(PrepareMdfeDocument::class)->cteOptionLabel($document),
            ])
            ->all();
    }

    /** @param array<int, int|string> $values @return array<int|string, string> */
    protected function cteOptionLabels(array $values): array
    {
        $line = CiotLineEnum::tryFrom((string) ($this->data['line'] ?? ''));

        if ($line === null) {
            return [];
        }

        return app(PrepareMdfeDocument::class)
            ->eligibleCteDocumentsByIds($line, $values, $this->batchId)
            ->mapWithKeys(fn (CteDocument $document): array => [
                $document->id => app(PrepareMdfeDocument::class)->cteOptionLabel($document),
            ])
            ->all();
    }

    /** @return array<int, string> */
    protected function ciotOptions(mixed $lineValue): array
    {
        $line = CiotLineEnum::tryFrom((string) $lineValue);

        if ($line === null) {
            return [];
        }

        return app(PrepareMdfeDocument::class)
            ->eligibleCiots($line, $this->batchId)
            ->mapWithKeys(fn (Ciot $ciot): array => [
                $ciot->id => app(PrepareMdfeDocument::class)->ciotOptionLabel($ciot),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function batchPrefillState(): array
    {
        $batch = $this->contextBatch();

        if ($batch === null) {
            return [];
        }

        $authorizedDocuments = $batch->documents()
            ->eligibleForNewMdfe()
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->whereNotNull('access_key')
            ->get();
        $lines = $authorizedDocuments
            ->map(fn (CteDocument $document): ?CiotLineEnum => CiotLineEnum::tryFrom((string) ($document->snapshot['line'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($lines->count() !== 1) {
            return [];
        }

        $line = $lines->first();
        $documents = app(PrepareMdfeDocument::class)->eligibleCteDocumentsByIds(
            $line,
            $authorizedDocuments->pluck('id')->all(),
            $batch->id,
        );

        if ($documents->isEmpty()
            || (in_array($line, [CiotLineEnum::TankAlcohol, CiotLineEnum::GeneralCargo], true) && $documents->count() !== 1)) {
            return [];
        }

        $ciot = app(PrepareMdfeDocument::class)->eligibleCiots($line, $batch->id)->first();
        $newCiotData = $this->defaultNewCiotData($line);

        try {
            $prefill = app(PrefillCiotFromDocuments::class)->handle($documents, $batch);
            $this->prefillWarnings = $prefill['warnings'];
            $this->routeRanking = $prefill['ranking'];
            $newCiotData = array_replace_recursive($newCiotData, $this->normalizePrefill($prefill['form']));
            $newCiotData['line'] = $line->value;
            $newCiotData['status'] = CiotStatusEnum::DRAFT->value;
        } catch (DomainException $exception) {
            $this->prefillWarnings = [$exception->getMessage()];
        }

        return [
            'line' => $line->value,
            'cte_document_ids' => $documents->pluck('id')->all(),
            'ciot_mode' => $ciot === null ? 'new' : 'existing',
            'ciot_id' => $ciot?->id,
            'driver_id' => $this->defaultDriverId($line),
            'new_ciot' => $newCiotData,
        ];
    }

    private function defaultDriverId(CiotLineEnum $line): ?int
    {
        $operationalProfile = $line->operationalProfile();
        $drivers = MdfeDriver::query()
            ->where('line', $operationalProfile->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'is_default']);

        if ($operationalProfile === CiotLineEnum::VehicleRemoval) {
            return $drivers->firstWhere('is_default', true)?->id;
        }

        return $drivers->count() === 1 ? (int) $drivers->first()->id : null;
    }

    /**
     * @param  array<int, int|string>  $selectedIds
     */
    private function updateSelection(Set $set, mixed $lineValue, array $selectedIds, array $currentCiotData = []): void
    {
        $line = CiotLineEnum::tryFrom((string) $lineValue);
        $this->prefillWarnings = [];
        $this->routeRanking = '';
        $set('ciot_id', null);
        $set('general_cargo', ['type' => null, 'ncm' => null, 'weight_kg' => null]);

        if ($line === null || $selectedIds === []) {
            $set('new_ciot', $line === null
                ? []
                : $this->preserveCiotSelections($this->defaultNewCiotData($line), $currentCiotData));

            return;
        }

        $documents = app(PrepareMdfeDocument::class)
            ->eligibleCteDocumentsByIds($line, $selectedIds, $this->batchId);

        if ($documents->count() !== count($selectedIds)) {
            $this->prefillWarnings = ['Um ou mais CT-es deixaram de estar elegíveis. Atualize a seleção antes de continuar.'];
            $set('new_ciot', $this->preserveCiotSelections($this->defaultNewCiotData($line), $currentCiotData));

            return;
        }

        try {
            $prefill = app(PrefillCiotFromDocuments::class)->handle($documents, $this->contextBatch());
        } catch (DomainException $exception) {
            $this->prefillWarnings = [$exception->getMessage()];
            $set('new_ciot', $this->preserveCiotSelections($this->defaultNewCiotData($line), $currentCiotData));

            return;
        }

        $this->prefillWarnings = $prefill['warnings'];
        $this->routeRanking = $prefill['ranking'];
        $newCiotData = array_replace_recursive(
            $this->defaultNewCiotData($line),
            $this->normalizePrefill($prefill['form']),
        );
        $newCiotData['line'] = $line->value;
        $newCiotData['status'] = CiotStatusEnum::DRAFT->value;
        $set('new_ciot', $this->preserveCiotSelections($newCiotData, $currentCiotData));
    }

    /** @param array<string, mixed> $newCiotData @param array<string, mixed> $currentCiotData @return array<string, mixed> */
    private function preserveCiotSelections(array $newCiotData, array $currentCiotData): array
    {
        foreach (['travel_start_at', 'travel_end_at'] as $field) {
            if (filled($currentCiotData[$field] ?? null)) {
                $newCiotData[$field] = $currentCiotData[$field];
            }
        }

        if (is_array($currentCiotData['vehicle_ids'] ?? null)) {
            $newCiotData['vehicle_ids'] = array_values($currentCiotData['vehicle_ids']);
        }

        return $newCiotData;
    }

    /** @return array<string, mixed> */
    private function defaultNewCiotData(CiotLineEnum $line): array
    {
        return [
            'status' => CiotStatusEnum::DRAFT->value,
            'line' => $line->value,
            'vehicle_ids' => [],
            'travel_start_at' => today()->toDateString(),
            'travel_end_at' => today()->addDay()->toDateString(),
            'indicators' => [
                'ind_alto_desempenho' => (bool) config('ciot.defaults.ind_alto_desempenho'),
                'ind_retorno_vazio' => (bool) config('ciot.defaults.ind_retorno_vazio'),
                'composicao_veicular' => (bool) config('ciot.defaults.composicao_veicular'),
            ],
        ];
    }

    /** @param array<string, mixed> $prefill @return array<string, mixed> */
    private function normalizePrefill(array $prefill): array
    {
        $state = [];

        foreach ($prefill as $key => $value) {
            if (str_contains($key, '.')) {
                Arr::set($state, $key, $value);
            } else {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    protected function selectionSummary(mixed $selectedIds, mixed $lineValue): HtmlString|string
    {
        if (! is_array($selectedIds) || $selectedIds === []) {
            return 'Selecione os CT-es para revisar a ordem e os dados da operação.';
        }

        $line = CiotLineEnum::tryFrom((string) $lineValue);

        if ($line === null) {
            return 'Selecione a modalidade antes de escolher os CT-es.';
        }

        $documents = app(PrepareMdfeDocument::class)
            ->eligibleCteDocumentsByIds($line, $selectedIds, $this->batchId);

        if ($documents->count() !== count($selectedIds)) {
            return new HtmlString('<p class="text-warning-600">Um ou mais CT-es selecionados não estão mais elegíveis para emissão.</p>');
        }

        $rows = $documents->map(function (CteDocument $document, int $position): string {
            return '<li>'.e(($position + 1).'. CT-e '.$document->cte_number.'/'.$document->series
                .' · frete R$ '.number_format((float) ($document->snapshot['value'] ?? 0), 2, ',', '.')).'</li>';
        })->implode('');

        return new HtmlString('<p><strong>'.count($selectedIds).' CT-e(s) em ordem de emissão:</strong></p><ol class="list-decimal pl-5">'.$rows.'</ol>');
    }

    private function prefillNotice(): HtmlString|string
    {
        $messages = $this->prefillWarnings;

        if ($this->routeRanking !== '') {
            array_unshift($messages, 'Rotas sugeridas: '.$this->routeRanking);
        }

        if ($messages === []) {
            return 'Selecione CT-es para preencher os dados derivados. Confira os valores antes de emitir o CIOT.';
        }

        return new HtmlString('<ul class="list-disc pl-5">'.collect($messages)
            ->map(fn (string $message): string => '<li>'.e($message).'</li>')
            ->implode('').'</ul>');
    }

    private function contextBatch(): ?CteEmissionBatch
    {
        if ($this->batchId === null) {
            return null;
        }

        return CteEmissionBatch::query()->findOrFail($this->batchId);
    }
}
