<?php

namespace App\Filament\Resources;

use App\Enums\CompanyEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Exports\RegistersExport;
use App\Exports\RegistersJsonExport;
use App\Filament\Resources\RegisterResource\Pages\CreateRegister;
use App\Filament\Resources\RegisterResource\Pages\EditRegister;
use App\Filament\Resources\RegisterResource\Pages\ListRegisters;
use App\Filament\Resources\RegisterResource\Pages\ViewRegister;
use App\Models\CteDocument;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\Cte\CreateCteEmissionBatch;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use App\Services\PdfExtractorService;
use App\Services\WhatsappExtractorService;
use Exception;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordCheckboxPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Excel as ExcelExcel;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;

class RegisterResource extends Resource
{
    /** @var array<string, string> */
    public const VEHICLE_CONDITION_OPTIONS = [
        'ANDA' => 'ANDA',
        'Ñ ANDA' => 'Ñ ANDA',
        'DOC - OK' => 'DOC - OK',
        'AMI - OK' => 'AMI - OK',
        'FIS - OK' => 'FIS - OK',
        'ND - OK' => 'ND - OK',
        'MOTO' => 'MOTO',
    ];

    /** @var array<string, string> */
    public const SPREADSHEET_COLUMN_COLOR_ATTRIBUTES = [
        'vehicle_plate' => 'vehicle_plate_color',
        'vehicle_model' => 'vehicle_model_color',
        'origin_city' => 'origin_city_color',
        'destination_city' => 'destination_city_color',
        'deadline_withdraw' => 'deadline_withdraw_color',
        'deadline_delivery' => 'deadline_delivery_color',
    ];

    /** @var array<string, string> */
    public const SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES = [
        'deadline_withdraw_color' => 'deadline_withdraw',
        'deadline_delivery_color' => 'deadline_delivery',
    ];

    /** @var array<int, string> */
    public const SPREADSHEET_DEADLINE_ALERT_STATUSES = [
        RegisterStatusEnum::PENDING->value,
        RegisterStatusEnum::AVAILABLE->value,
        RegisterStatusEnum::PENDING_DAILY_RATES->value,
    ];

    /** @var array<int, string> */
    public const SPREADSHEET_FILTERABLE_COLUMNS = [
        'vehicle_plate',
        'vehicle_model',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'status',
        'tow_yard',
    ];

    /** @var array<string, string> */
    private const SPREADSHEET_COLOR_FIELD_LABELS = [
        'vehicle_plate_color' => 'Placa',
        'vehicle_model_color' => 'Modelo',
        'origin_city_color' => 'Origem',
        'destination_city_color' => 'Destino',
        'deadline_withdraw_color' => 'Coleta',
        'deadline_delivery_color' => 'Entrega',
    ];

    /** @var array<string, array<string, string>> */
    private const SPREADSHEET_COLOR_CHOICES = [
        'vehicle_plate_color' => [
            'blue' => 'Azul — região afastada',
        ],
        'vehicle_model_color' => [
            'red' => 'Vermelho — carga formada para cegonha',
        ],
        'origin_city_color' => [
            'red' => 'Vermelho — próxima carga formada',
        ],
        'destination_city_color' => [
            'red' => 'Vermelho — ligar com urgência',
            'yellow' => 'Amarelo — veículo com pendências',
            'green' => 'Verde — coleta hoje',
        ],
        'deadline_withdraw_color' => [
            'red' => 'Vermelho — prazo vencendo',
        ],
        'deadline_delivery_color' => [
            'red' => 'Vermelho — prazo vencendo',
        ],
    ];

    protected static ?string $model = Register::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Registros';

    protected static ?string $modelLabel = 'Registro Copart';

    protected static ?string $pluralModelLabel = 'Registros Copart';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Dados do veículo e empresa')->schema([
                    Select::make('company')
                        ->label('Empresa')
                        ->options([
                            'copart' => 'Copart',
                            'millan' => 'Millan',
                        ])
                        ->default('copart')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('pdf_path', null)),
                    TextInput::make('vehicle_model')
                        ->label('Veículo')
                        ->required()
                        ->maxLength(30),
                    TextInput::make('vehicle_plate')
                        ->label('Placa do veículo')
                        ->required()
                        ->maxLength(7)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Placa já cadastrada',
                        ]),
                    TextInput::make('origin_city')
                        ->label('Cidade origem')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('destination_city')
                        ->label('Cidade destino')
                        ->required()
                        ->maxLength(50),
                    TextInput::make('vehicle_id')
                        ->label('Código do veículo')
                        ->required()
                        ->numeric(),
                ]),

                Section::make('Datas')->schema([
                    DatePicker::make('deadline_withdraw')
                        ->label('Data limite para recolha')
                        ->validationAttribute('Data limite para recolha')
                        ->required()
                        ->native(false)
                        ->weekStartsOnSunday()
                        ->closeOnDateSelection()
                        ->live(),
                    DatePicker::make('deadline_delivery')
                        ->label('Data limite entrega')
                        ->validationAttribute('Data limite entrega')
                        ->required()
                        ->native(false)
                        ->weekStartsOnSunday()
                        ->closeOnDateSelection()
                        ->live(),
                    DatePicker::make('collected_date')
                        ->label('Data da recolha')
                        ->validationAttribute('Data da recolha')
                        ->native(false)
                        ->weekStartsOnSunday()
                        ->closeOnDateSelection()
                        ->live(),
                ]),

                Section::make('Detalhes Remoção e Status')->schema([
                    TextInput::make('driver')
                        ->label('Motorista')
                        ->maxLength(30),
                    TextInput::make('driver_plate')
                        ->label('Placa guincho')
                        ->maxLength(7),
                    Select::make('status')
                        ->label('Situação')
                        ->options(RegisterStatusEnum::optionsWithLabels())
                        ->enum(RegisterStatusEnum::class)
                        ->default(RegisterStatusEnum::PENDING)
                        ->required(),
                    TextInput::make('tow_yard')
                        ->label('Pátio')
                        ->maxLength(50),
                ]),

                Section::make('Financeiro')->schema([
                    TextInput::make('payment_code')
                        ->label('Código pagamento'),
                    TextInput::make('insurance')
                        ->label('Seguradora'),
                    Money::make('fipe_value')
                        ->label('Valor FIPE'),
                    Money::make('value')
                        ->label('Valor')
                        ->required(),

                ]),

                Section::make('Documentos e Observações')->schema([
                    FileUpload::make('pdf_path')
                        ->label('Carta de Remoção')
                        ->disk('s3')
                        ->directory(config('awss3.s3_bucket'))
                        ->visibility('public')
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes(['application/pdf'])
                        ->required(fn (Get $get): bool => $get('company') === 'copart')
                        ->preserveFilenames()
                        ->live()
                        ->visible(fn (Get $get): bool => $get('company') === 'copart')
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if (blank($state)) {
                                $set('vehicle_model', '');
                                $set('vehicle_plate', '');
                                $set('origin_city', '');
                                $set('destination_city', '');
                                $set('vehicle_id', '');
                                $set('insurance', '');

                                return;
                            }

                            $tempLocalPath = null;

                            try {
                                $tempLocalPath = tempnam(sys_get_temp_dir(), 'pdf_process_');

                                if (! is_string($tempLocalPath)) {
                                    throw new RuntimeException('Não foi possível preparar o arquivo PDF.');
                                }

                                if (file_put_contents($tempLocalPath, $state->get()) === false) {
                                    throw new RuntimeException('Não foi possível preparar o arquivo PDF.');
                                }

                                $extractor = app(PdfExtractorService::class);

                                $extractedData = $extractor->extractData($tempLocalPath);

                                if (isset($extractedData['error'])) {
                                    Notification::make()
                                        ->danger()
                                        ->title('Erro na Extração')
                                        ->body($extractedData['error'])
                                        ->send();

                                    return;
                                }

                                $set('vehicle_model', $extractedData['vehicle_model'] ?? $get('vehicle_model'));
                                $set('vehicle_plate', $extractedData['vehicle_plate'] ?? $get('vehicle_plate'));
                                $set('origin_city', $extractedData['origin_city'] ?? $get('origin_city'));
                                $set('destination_city', $extractedData['destination_city'] ?? $get('destination_city'));
                                $set('vehicle_id', $extractedData['vehicle_id'] ?? $get('vehicle_id'));
                                $set('insurance', $extractedData['insurance'] ?? $get('insurance'));

                                if (! empty($extractedData['deadline_withdraw'])) {
                                    try {
                                        $date = Carbon::createFromFormat('d/m/Y', $extractedData['deadline_withdraw'])->format('Y-m-d');
                                        $set('deadline_withdraw', $date);
                                    } catch (Exception $e) {
                                        Log::warning('Could not parse deadline_withdraw from PDF: '.$extractedData['deadline_withdraw']);
                                    }
                                }
                                if (! empty($extractedData['deadline_delivery'])) {
                                    try {
                                        $date = Carbon::createFromFormat('d/m/Y', $extractedData['deadline_delivery'])->format('Y-m-d');
                                        $set('deadline_delivery', $date);
                                    } catch (Exception $e) {
                                        Log::warning('Could not parse deadline_delivery from PDF: '.$extractedData['deadline_delivery']);
                                    }
                                }

                                if (! empty($extractedData['origin_phones'])) {
                                    $phoneString = implode(' / ', $extractedData['origin_phones']);
                                    $notes = $get('notes');
                                    $set('notes', ($notes ? $notes."\n" : '').'Telefones Origem: '.$phoneString);
                                }

                                Notification::make()
                                    ->success()
                                    ->title('Dados Extraídos')
                                    ->body('Campos preenchidos com base no PDF. Por favor, revise e complete as informações.')
                                    ->send();

                            } catch (Throwable $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Erro Inesperado')
                                    ->body('Ocorreu um erro ao tentar extrair dados do PDF: '.$e->getMessage())
                                    ->send();
                                Log::error('Unexpected error during PDF extraction service call: '.$e->getMessage(), ['state' => $state]);
                            } finally {
                                if (is_string($tempLocalPath) && file_exists($tempLocalPath)) {
                                    unlink($tempLocalPath);
                                }
                            }
                        })
                        ->helperText('Faça upload do PDF para tentar preencher os campos automaticamente.'),
                    Textarea::make('notes')
                        ->label('Observações')
                        ->maxLength(1500)
                        ->rows(8)
                        ->columnSpanFull()
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state) {
                            if ($get('company') !== 'millan' || blank($state)) {
                                return;
                            }

                            if (str_starts_with(trim((string) $state), 'Contato:')) {
                                return;
                            }

                            try {
                                $extractor = app(WhatsappExtractorService::class);
                                $extractedData = $extractor->extractData($state);

                                if (empty($extractedData)) {
                                    // Notification::make()
                                    //     ->warning()
                                    //     ->title('Nenhum dado extraído')
                                    //     ->body('Não foi possível extrair informações do texto. Por favor, preencha manualmente.')
                                    //     ->send();

                                    return;
                                }

                                $contactPhone = $extractedData['contact_phone'] ?? null;
                                unset($extractedData['contact_phone']);

                                foreach ($extractedData as $key => $value) {
                                    if (! blank($value)) {
                                        $set($key, $value);
                                    }
                                }

                                $set('notes', $contactPhone ? 'Contato: '.$contactPhone : null);

                                Notification::make()
                                    ->success()
                                    ->title('Dados Extraídos do Texto')
                                    ->body('Campos preenchidos com base na mensagem. Por favor, revise.')
                                    ->send();

                            } catch (Exception $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Erro na Extração')
                                    ->body('Ocorreu um erro ao processar o texto.')
                                    ->send();
                                Log::error('WhatsappExtractorService Error: '.$e->getMessage(), ['text' => $state]);
                            }
                        }),
                    FileUpload::make('consignor_letter_path')
                        ->label('Carta do Comitente')
                        ->disk('s3')
                        ->directory(config('awss3.s3_bucket'))
                        ->visibility('public')
                        ->downloadable()
                        ->openable()
                        ->acceptedFileTypes(['application/pdf'])
                        ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, Get $get): string {
                            $plate = strtoupper(str_replace('-', '', (string) $get('vehicle_plate')));

                            return "CartaDoComitente {$plate}.pdf";
                        })
                        ->deleteUploadedFileUsing(function (string|TemporaryUploadedFile $file): void {
                            if ($file instanceof TemporaryUploadedFile) {
                                return;
                            }

                            Storage::disk('s3')->delete($file);
                        })
                        ->visible(fn (Get $get): bool => $get('company') === 'copart'),
                ]),

                Section::make('CT-e e entrega')
                    ->columns(3)
                    ->visibleOn('view')
                    ->schema([
                        Placeholder::make('latestAuthorizedCteDocument.cte_number')
                            ->label('Número do CT-e')
                            ->content(fn (?Register $record): string => $record?->latestAuthorizedCteDocument?->cte_number ?? 'Não informado'),
                        Placeholder::make('latestAuthorizedCteDocument.issued_at')
                            ->label('Emissão do CT-e')
                            ->content(fn (?Register $record): string => $record?->latestAuthorizedCteDocument?->issued_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Não informado'),
                        Placeholder::make('delivery_confirmed_at')
                            ->label('Data da entrega')
                            ->content(fn (?Register $record): string => $record?->delivery_confirmed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Não informado'),
                    ]),
                Section::make('Revisão de integração')
                    ->visibleOn('view')
                    ->schema([
                        Placeholder::make('removal_import_attention')
                            ->label('Situação')
                            ->content(function (?Register $record): string {
                                $summary = $record === null ? null : self::removalImportSummary($record);

                                return $summary !== null
                                    ? "Pendência de integração: {$summary}. Abra a ação Revisão na listagem de registros."
                                    : 'Nenhuma revisão de e-mail pendente.';
                            }),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->columns(self::tableColumns())
            ->striped()
            ->searchPlaceholder('Buscar placa, modelo ou cidade')
            ->emptyStateHeading('Não há registros!')
            ->emptyStateDescription('Nenhum registro encontrado. Tente ajustar sua busca ou crie um novo registro.')
            ->filters(self::tableFilters(), layout: FiltersLayout::AboveContentCollapsible)
            ->actions([
                Action::make('viewRemovalImport')
                    ->label(fn (Register $record): string => 'Revisão: '.(self::removalImportSummary($record) ?? 'pendente'))
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (Register $record): bool => self::hasUnresolvedRemovalImport($record))
                    ->url(function (Register $record): ?string {
                        $item = $record->getRelation('unresolvedRemovalImports')->first();

                        return $item === null
                            ? null
                            : IntegrationInboxItemResource::getUrl('view', ['record' => $item]);
                    }),
                EditAction::make()->iconButton(),
                Action::make('updateStatusSingle')
                    ->label('Situação')
                    ->icon('heroicon-o-arrow-path')
                    ->form(self::getUpdateStatusFormSchema())
                    ->action(function (array $data, Register $record): void {
                        self::updateRegisterStatus($record, $data);
                    })
                    ->color('primary')
                    ->modalWidth('lg'),
                Action::make('openPdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (Register $record): ?string => $record->pdf_path
                        ? Storage::disk('s3')->url($record->pdf_path)
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (Register $record): bool => filled($record->pdf_path)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Apagar Selecionados'),
                    BulkAction::make('createCteEmissionBatch')
                        ->label('Criar lote de CT-e')
                        ->icon('heroicon-o-document-plus')
                        ->modalDescription(fn (Collection $records): ?string => self::getCteReemissionWarning($records))
                        ->form(fn (Collection $records): array => self::getCteEmissionBatchForm($records))
                        ->action(function (Collection $records, array $data) {
                            $batch = app(CreateCteEmissionBatch::class)->handle(
                                $records,
                                auth()->user(),
                                $data['execution_mode'],
                                reemissionConfirmed: (bool) ($data['confirm_reemission'] ?? false),
                                reemissionReason: $data['reemission_reason'] ?? null,
                            );

                            Notification::make()
                                ->success()
                                ->title('Lote de CT-e criado')
                                ->body('Revise o lote antes de aprovar a execucao.')
                                ->send();

                            return redirect(CteEmissionBatchResource::getUrl('view', ['record' => $batch]));
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('export')
                        ->label('Exportar para Excel')
                        ->icon('heroicon-o-document-arrow-down')
                        ->action(function (Collection $records) {
                            $filename = 'registros-'.now()->format('d-m-Y').'.xlsx';

                            return Excel::download(new RegistersExport($records), $filename, ExcelExcel::XLSX);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('copyAsImage')
                        ->label('Copiar como imagem')
                        ->icon('heroicon-o-photo')
                        ->action(function (Collection $records, LivewireComponent $livewire): void {
                            $rows = $records->map(fn (Register $record): array => [
                                'plate' => $record->vehicle_plate,
                                'model' => $record->vehicle_model,
                                'origin' => $record->origin_city,
                                'destination' => self::destinationWithInsurance($record->destination_city, $record),
                                'withdraw' => $record->deadline_withdraw?->format('d/m') ?? '-',
                                'delivery' => $record->deadline_delivery?->format('d/m') ?? '-',
                                'status' => $record->status->localizedLabel(),
                                'location' => $record->tow_yard ?: '-',
                                'condition' => $record->condition ?? '',
                                'value' => $record->value === null
                                    ? '-'
                                    : 'R$ '.number_format((float) $record->value, 2, ',', '.'),
                                'colors' => [
                                    'vehicle_plate_color' => $record->vehicle_plate_color,
                                    'vehicle_model_color' => $record->vehicle_model_color,
                                    'origin_city_color' => $record->origin_city_color,
                                    'destination_city_color' => $record->destination_city_color,
                                    'deadline_withdraw_color' => self::spreadsheetCellColorValue($record, 'deadline_withdraw_color'),
                                    'deadline_delivery_color' => self::spreadsheetCellColorValue($record, 'deadline_delivery_color'),
                                ],
                            ])->values()->all();

                            $livewire->dispatch('registers-copy-as-image', rows: $rows);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('exportJson')
                        ->label('Exportar para JSON')
                        ->icon('heroicon-o-code-bracket-square')
                        ->action(function (Collection $records) {
                            $filename = 'registros-'.now()->format('d-m-Y').'.json';

                            return (new RegistersJsonExport($records))->download($filename);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('updateStatusMulti')
                        ->label('Atualizar Situação (em massa)')
                        ->icon('heroicon-o-pencil-square')
                        ->form(self::getUpdateStatusFormSchema())
                        ->action(function (array $data, Collection $records): void {
                            $records->each(fn (Register $record) => self::updateRegisterStatus($record, $data));
                        })
                        ->color('primary')
                        ->modalWidth('lg')
                        ->deselectRecordsAfterCompletion(),
                ])->label('Ações em massa'),
            ])
            ->recordCheckboxPosition(RecordCheckboxPosition::BeforeCells)
            ->modifyQueryUsing(function (Builder $query): void {
                $query
                    ->withExists('unresolvedRemovalImports')
                    ->with('unresolvedRemovalImports:id,register_id,status,alerts,failure_reason')
                    ->with('latestAuthorizedCteDocument');

                $query->orderByRaw("
                    CASE status
                        WHEN 'analysis' THEN 0
                        WHEN 'pending' THEN 1
                        WHEN 'pending daily rates' THEN 2
                        WHEN 'available' THEN 3
                        WHEN 'collected' THEN 4
                        WHEN 'delivered' THEN 5
                        WHEN 'paid' THEN 6
                        ELSE 7
                    END ASC
                ")->orderBy('deadline_withdraw', 'asc');
            })
            ->recordUrl(fn (Register $record): string => static::getUrl('view', ['record' => $record]));
    }

    /** @return array<int, Column> */
    private static function tableColumns(): array
    {
        $columns = [
            TextColumn::make('company')
                ->label('Empresa')
                ->formatStateUsing(fn (CompanyEnum $state): string => $state->getLabel() ?? $state->value)
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('vehicle_plate')
                ->label('Placa')
                ->width('6rem')
                ->weight('bold')
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'vehicle_plate_color'))
                ->tooltip('Clique para alterar a cor da placa')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('vehicle_plate_color'))
                ->searchable(),
            TextColumn::make('vehicle_model')
                ->label('Modelo')
                ->width('11rem')
                ->wrap()
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'vehicle_model_color'))
                ->tooltip('Clique para alterar a cor do modelo')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('vehicle_model_color'))
                ->searchable(
                    query: function (Builder $query, string $search): Builder {
                        return $query
                            ->where('vehicle_model', 'like', "%{$search}%")
                            ->orWhereHas('cteDocuments', fn (Builder $query): Builder => $query->where('cte_number', 'like', "%{$search}%"));
                    },
                ),
            TextColumn::make('origin_city')
                ->label('Origem')
                ->width('15rem')
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'origin_city_color'))
                ->tooltip('Clique para alterar a cor da origem')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('origin_city_color'))
                ->searchable(),
            TextColumn::make('destination_city')
                ->label('Destino')
                ->formatStateUsing(fn (string $state, Register $record): string => self::destinationWithInsurance($state, $record))
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'destination_city_color'))
                ->tooltip('Clique para alterar a cor do destino')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('destination_city_color'))
                ->searchable(),
            TextColumn::make('vehicle_id')
                ->label('Código')
                ->searchable()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('deadline_withdraw')
                ->label('Coleta')
                ->date('d/m')
                ->width('3.75rem')
                ->alignCenter()
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'deadline_withdraw_color'))
                ->tooltip('Clique para alterar a cor da data de coleta')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('deadline_withdraw_color'))
                ->sortable(),
            TextColumn::make('collected_date')
                ->label('Data da coleta efetiva')
                ->date('d/m/Y')
                ->placeholder('-')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('deadline_delivery')
                ->label('Entrega')
                ->date('d/m')
                ->width('3.75rem')
                ->alignCenter()
                ->color(fn (Register $record): string => self::spreadsheetCellTextColor($record, 'deadline_delivery_color'))
                ->tooltip('Clique para alterar a cor da data de entrega')
                ->extraCellAttributes(fn (Register $record): array => self::spreadsheetMobileRecordLinkAttributes($record))
                ->action(self::spreadsheetCellColorAction('deadline_delivery_color'))
                ->sortable(),
            TextColumn::make('delivery_confirmed_at')
                ->label('Entrega confirmada')
                ->date('d/m/Y')
                ->placeholder('-')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')
                ->label('Situação')
                ->width('8rem')
                ->badge()
                ->color(fn (RegisterStatusEnum $state): string => $state->color())
                ->formatStateUsing(fn (RegisterStatusEnum $state): string => $state->localizedLabel())
                ->searchable(),
            TextColumn::make('latestAuthorizedCteDocument.cte_number')
                ->label('CT-e autorizado')
                ->badge()
                ->color('success')
                ->placeholder('-')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('unresolved_removal_imports_exists')
                ->label('Integração')
                ->state(fn (Register $record): ?string => self::removalImportSummary($record))
                ->badge()
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->action(
                    Action::make('acknowledgeRemovalImport')
                        ->requiresConfirmation()
                        ->modalHeading('Tomar ciência das alterações')
                        ->modalDescription('Confirma ter ciência das alterações sinalizadas nesta importação? O aviso deixará de ser exibido.')
                        ->visible(fn (Register $record): bool => self::firstUnresolvedRemovalImport($record)?->status === 'alert')
                        ->action(function (Register $record): void {
                            $item = self::firstUnresolvedRemovalImport($record);

                            if ($item === null) {
                                return;
                            }

                            try {
                                app(ResolveRemovalRequestImport::class)->acknowledge($item, auth()->user());

                                Notification::make()
                                    ->title('Ciência registrada com sucesso.')
                                    ->success()
                                    ->send();
                            } catch (Throwable $exception) {
                                Notification::make()
                                    ->title('Não foi possível registrar a ciência.')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                )
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('driver')
                ->label('Motorista')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('driver_plate')
                ->label('Placa guincho')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('tow_yard')
                ->label('Local')
                ->width('6rem')
                ->wrap()
                ->searchable()
                ->toggleable(),
            SelectColumn::make('condition')
                ->label('Condição')
                ->width('8rem')
                ->wrapHeader()
                ->options(self::VEHICLE_CONDITION_OPTIONS)
                ->placeholder('—')
                ->selectablePlaceholder()
                ->rules(['nullable', Rule::in(array_keys(self::VEHICLE_CONDITION_OPTIONS))]),
            TextColumn::make('value')
                ->label('Valor')
                ->width('8rem')
                ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : 'R$ '.number_format((float) $state, 2, ',', '.')),
            TextColumn::make('fipe_value')
                ->label('Valor FIPE')
                ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : 'R$ '.number_format((float) $state, 2, ',', '.'))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('insurance')
                ->label('Seguradora')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('payment_code')
                ->label('Código pagamento')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('payment_deferred_at')
                ->label('Pagamento adiado')
                ->dateTime('d/m/Y H:i')
                ->placeholder('-')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('notes')
                ->label('Observações')
                ->searchable()
                ->wrap()
                ->limit(60)
                ->tooltip(fn (?string $state): ?string => $state)
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('pdf_path')
                ->label('Carta remoção')
                ->formatStateUsing(fn (?string $state): ?string => $state ? 'Abrir PDF' : '-')
                ->url(fn (?Register $record): ?string => $record?->pdf_path ? Storage::disk('s3')->url($record->pdf_path) : null)
                ->openUrlInNewTab()
                ->hidden(fn (?Register $record): bool => $record?->company === CompanyEnum::MILLAN)
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('consignor_letter_path')
                ->label('Carta comitente')
                ->formatStateUsing(fn (?string $state): ?string => $state ? 'Comitente' : '-')
                ->url(fn (?Register $record): ?string => $record?->consignor_letter_path ? Storage::disk('s3')->url($record->consignor_letter_path) : null)
                ->openUrlInNewTab()
                ->hidden(fn (?Register $record): bool => $record?->company === CompanyEnum::MILLAN)
                ->toggleable(isToggledHiddenByDefault: true),
        ];

        foreach ($columns as $column) {
            if (in_array($column->getName(), self::SPREADSHEET_FILTERABLE_COLUMNS, true)) {
                $column->extraHeaderAttributes(['data-register-column-filter-header' => 'true']);
            }
        }

        return $columns;
    }

    /** @return array<int, Filter|SelectFilter|TernaryFilter> */
    private static function tableFilters(): array
    {
        return [
            SelectFilter::make('company')
                ->label('Empresa')
                ->options([
                    'copart' => 'Copart',
                    'millan' => 'Millan',
                ]),
            TernaryFilter::make('cte_autorizado')
                ->label('CT-e autorizado')
                ->placeholder('Todos')
                ->trueLabel('Com CT-e autorizado')
                ->falseLabel('Sem CT-e autorizado')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereHas(
                        'cteDocuments',
                        fn (Builder $query): Builder => $query->where('status', CteDocumentStatusEnum::AUTHORIZED),
                    ),
                    false: fn (Builder $query): Builder => $query->whereDoesntHave(
                        'cteDocuments',
                        fn (Builder $query): Builder => $query->where('status', CteDocumentStatusEnum::AUTHORIZED),
                    ),
                ),
            self::dateRangeFilter('payment_deferred_at', 'Pagamento adiado'),
            self::decimalRangeFilter('fipe_value', 'Valor FIPE'),
        ];
    }

    /** @return array<string, string> */
    public static function spreadsheetColumnColorOptions(string $column): array
    {
        $attribute = self::SPREADSHEET_COLUMN_COLOR_ATTRIBUTES[$column] ?? null;

        if ($attribute === null) {
            return [];
        }

        if (isset(self::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute])) {
            return [
                'automatic' => 'Sem marcação',
                ...self::SPREADSHEET_COLOR_CHOICES[$attribute],
            ];
        }

        return self::spreadsheetColorOptions($attribute);
    }

    public static function spreadsheetColorAttributeForColumn(string $column): ?string
    {
        return self::SPREADSHEET_COLUMN_COLOR_ATTRIBUTES[$column] ?? null;
    }

    /** @return array<int, string> */
    public static function spreadsheetTowYardFilterOptions(): array
    {
        return Register::query()
            ->whereNotNull('tow_yard')
            ->where('tow_yard', '!=', '')
            ->distinct()
            ->orderBy('tow_yard')
            ->pluck('tow_yard')
            ->all();
    }

    /** @return array<int, string> */
    public static function spreadsheetOriginCityFilterOptions(): array
    {
        return Register::query()
            ->whereNotNull('origin_city')
            ->where('origin_city', '!=', '')
            ->distinct()
            ->orderBy('origin_city')
            ->pluck('origin_city')
            ->all();
    }

    /** @return array<int, string> */
    public static function spreadsheetDestinationCityFilterOptions(): array
    {
        return Register::query()
            ->whereNotNull('destination_city')
            ->where('destination_city', '!=', '')
            ->distinct()
            ->orderBy('destination_city')
            ->pluck('destination_city')
            ->all();
    }

    /** @return array<string, string> */
    private static function spreadsheetMobileRecordLinkAttributes(Register $record): array
    {
        return [
            'data-register-record-url' => static::getUrl('view', ['record' => $record]),
        ];
    }

    private static function destinationWithInsurance(string $destination, Register $record): string
    {
        return filled($record->insurance)
            ? "{$destination} - {$record->insurance}"
            : $destination;
    }

    private static function spreadsheetCellColorAction(string $attribute): Action
    {
        $options = self::spreadsheetColorOptions($attribute);
        $label = self::SPREADSHEET_COLOR_FIELD_LABELS[$attribute];

        return Action::make('edit'.Str::studly($attribute))
            ->label("Alterar cor de {$label}")
            ->modalHeading("Alterar cor de {$label}")
            ->modalSubmitActionLabel('Aplicar cor')
            ->modalWidth('sm')
            ->fillForm(fn (Register $record): array => [
                'color' => match (true) {
                    isset(self::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute]) && $record->{$attribute} === 'black' => 'black',
                    in_array($record->{$attribute}, [null, 'black'], true) => 'automatic',
                    default => $record->{$attribute},
                },
            ])
            ->form([
                Select::make('color')
                    ->label('Destaque')
                    ->options($options)
                    ->required()
                    ->in(array_keys($options))
                    ->native(false),
            ])
            ->action(function (Register $record, array $data) use ($attribute): void {
                $record->update([
                    $attribute => $data['color'] === 'automatic' ? null : $data['color'],
                ]);
            });
    }

    /** @return array<string, string> */
    private static function spreadsheetColorOptions(string $attribute): array
    {
        $options = [
            'automatic' => isset(self::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute])
                ? 'Automático — por prazo'
                : 'Automático — sem marcação',
        ];

        if (isset(self::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute])) {
            $options['black'] = 'Sem marcação';
        }

        return [...$options, ...self::SPREADSHEET_COLOR_CHOICES[$attribute]];
    }

    private static function spreadsheetCellTextColor(Register $record, string $attribute): string
    {
        return match (self::spreadsheetCellColorValue($record, $attribute)) {
            'blue' => 'info',
            'red' => 'danger',
            'yellow' => 'warning',
            'green' => 'success',
            default => 'gray',
        };
    }

    private static function spreadsheetCellColorValue(Register $record, string $attribute): ?string
    {
        $color = $record->{$attribute};
        $deadlineAttribute = self::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute] ?? null;

        if ($color === null && $deadlineAttribute !== null
            && in_array($record->status->value, self::SPREADSHEET_DEADLINE_ALERT_STATUSES, true)) {
            $deadline = $record->{$deadlineAttribute};

            if ($deadline !== null && $deadline->toDateString() <= today('America/Sao_Paulo')->toDateString()) {
                return 'red';
            }
        }

        return $color;
    }

    private static function dateRangeFilter(string $column, string $label): Filter
    {
        return Filter::make("{$column}_range")
            ->label($label)
            ->form([
                DatePicker::make('from')
                    ->label('De')
                    ->native(false),
                DatePicker::make('until')
                    ->label('Até')
                    ->native(false),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                return $query
                    ->when(
                        $data['from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate($column, '>=', $date),
                    )
                    ->when(
                        $data['until'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate($column, '<=', $date),
                    );
            });
    }

    private static function decimalRangeFilter(string $column, string $label): Filter
    {
        return Filter::make("{$column}_range")
            ->label($label)
            ->form([
                TextInput::make('minimum')
                    ->label('Mínimo')
                    ->numeric(),
                TextInput::make('maximum')
                    ->label('Máximo')
                    ->numeric(),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                return $query
                    ->when(
                        $data['minimum'] ?? null,
                        fn (Builder $query, string $minimum): Builder => $query->where($column, '>=', $minimum),
                    )
                    ->when(
                        $data['maximum'] ?? null,
                        fn (Builder $query, string $maximum): Builder => $query->where($column, '<=', $maximum),
                    );
            });
    }

    protected static function getUpdateStatusFormSchema(): array
    {
        return [
            Select::make('status')
                ->label('Situação')
                ->options(RegisterStatusEnum::optionsWithLabels())
                ->enum(RegisterStatusEnum::class)
                ->required()
                ->live(),
            DatePicker::make('collected_date')
                ->label('Data da coleta')
                ->native(false)
                ->visible(fn (Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value),
            TextInput::make('driver')
                ->label('Motorista (opcional)')
                ->visible(fn (Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                ->maxLength(30),
            TextInput::make('driver_plate')
                ->label('Placa guincho (opcional)')
                ->visible(fn (Get $get) => $get('status') === RegisterStatusEnum::COLLECTED->value)
                ->requiredIf('status', RegisterStatusEnum::COLLECTED->value)
                ->maxLength(7),
        ];
    }

    protected static function updateRegisterStatus(Register $record, array $data): void
    {
        $updateData = ['status' => $data['status']];

        if ($data['status'] === RegisterStatusEnum::COLLECTED->value) {
            if (isset($data['collected_date'])) {
                $updateData['collected_date'] = $data['collected_date'];
            }
            if (isset($data['driver'])) {
                $updateData['driver'] = $data['driver'];
            }
            if (isset($data['driver_plate'])) {
                $updateData['driver_plate'] = $data['driver_plate'];
            }
        }

        $record->update($updateData);
    }

    /**
     * @param  Collection<int, Register>  $records
     * @return array<string, CteDocument> documentos autorizados atuais, indexados por register_id
     */
    private static function authorizedCtesByRegister(Collection $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }

        return CteDocument::query()
            ->whereIn('register_id', $records->modelKeys())
            ->where('status', CteDocumentStatusEnum::AUTHORIZED)
            ->orderBy('authorized_at')
            ->orderBy('id')
            ->get()
            ->keyBy('register_id')
            ->all();
    }

    /** @param  Collection<int, Register>  $records */
    private static function getCteReemissionWarning(Collection $records): ?string
    {
        $authorized = self::authorizedCtesByRegister($records);

        if ($authorized === []) {
            return null;
        }

        $list = collect($authorized)
            ->map(fn (CteDocument $document): string => "Remoção #{$document->register_id} — CT-e {$document->cte_number}")
            ->implode('; ');

        return "Os registros selecionados já possuem CT-e autorizado e serão incluídos em um novo lote como reemissão: {$list}. O CT-e anterior será marcado como substituído quando este lote for aprovado.";
    }

    /**
     * @param  Collection<int, Register>  $records
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private static function getCteEmissionBatchForm(Collection $records): array
    {
        $form = [
            Select::make('execution_mode')
                ->label('Modo de execucao')
                ->options([
                    'dry_run' => 'Dry-run (sem autorizacao fiscal)',
                    'live' => 'Emissao real',
                ])
                ->default('dry_run')
                ->required(),
        ];

        if (self::authorizedCtesByRegister($records) === []) {
            return $form;
        }

        $form[] = Checkbox::make('confirm_reemission')
            ->label('Confirmo a reemissão dos CT-es listados')
            ->helperText('Marque apenas se você realmente deseja refazer o CT-e destes registros. Confira a lista antes de continuar.')
            ->accepted();
        $form[] = TextInput::make('reemission_reason')
            ->label('Motivo da reemissão (opcional)')
            ->placeholder('Ex.: recusado pelo contratante')
            ->maxLength(255);

        return $form;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegisters::route('/'),
            'create' => CreateRegister::route('/create'),
            'view' => ViewRegister::route('/{record}'),
            'edit' => EditRegister::route('/{record}/edit'),
        ];
    }

    private static function hasUnresolvedRemovalImport(Register $record): bool
    {
        return $record->relationLoaded('unresolvedRemovalImports')
            ? $record->getRelation('unresolvedRemovalImports')->isNotEmpty()
            : (bool) $record->unresolved_removal_imports_exists;
    }

    private static function firstUnresolvedRemovalImport(Register $record): ?IntegrationInboxItem
    {
        $items = $record->relationLoaded('unresolvedRemovalImports')
            ? $record->getRelation('unresolvedRemovalImports')
            : $record->unresolvedRemovalImports()->get();

        return $items->first();
    }

    private static function removalImportSummary(Register $record): ?string
    {
        $item = self::firstUnresolvedRemovalImport($record);

        if ($item === null) {
            return null;
        }

        if ($item->hasRemovalAlert()) {
            return implode(', ', $item->removalAlertLabels());
        }

        return $item->failureReasonLabel() ?? 'Revisão pendente';
    }
}
