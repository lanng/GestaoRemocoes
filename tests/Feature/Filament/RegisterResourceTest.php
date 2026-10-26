<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\RegisterResource;
use App\Filament\Resources\RegisterResource\Pages\CreateRegister;
use App\Filament\Resources\RegisterResource\Pages\ListRegisters;
use App\Filament\Resources\RegisterResource\Pages\ViewRegister;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Enums\RecordCheckboxPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RegisterResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_register_list_displays_operational_fields_as_spreadsheet_columns(): void
    {
        $component = Livewire::test(ListRegisters::class);
        $table = $component->instance()->getTable();
        $columns = $table->getColumns();
        $this->assertSame(RecordCheckboxPosition::BeforeCells, $table->getRecordCheckboxPosition());

        $this->assertSame([
            'vehicle_plate',
            'vehicle_model',
            'origin_city',
            'destination_city',
            'deadline_withdraw',
            'deadline_delivery',
            'status',
            'tow_yard',
            'condition',
            'value',
        ], collect($columns)
            ->reject(fn ($column): bool => $column->isToggledHidden())
            ->keys()
            ->all());
        $this->assertSame('Placa', $columns['vehicle_plate']->getLabel());
        $this->assertSame('Modelo', $columns['vehicle_model']->getLabel());
        $this->assertSame('Coleta', $columns['deadline_withdraw']->getLabel());
        $this->assertSame('Entrega', $columns['deadline_delivery']->getLabel());
        $this->assertSame('Condição', $columns['condition']->getLabel());
        $this->assertSame('6rem', $columns['vehicle_plate']->getWidth());
        $this->assertSame('11rem', $columns['vehicle_model']->getWidth());
        $this->assertSame('15rem', $columns['origin_city']->getWidth());
        $this->assertTrue($columns['vehicle_model']->canWrap());
        $this->assertSame('3.75rem', $columns['deadline_withdraw']->getWidth());
        $this->assertSame($columns['deadline_withdraw']->getWidth(), $columns['deadline_delivery']->getWidth());
        $this->assertSame('3.75rem', $columns['deadline_delivery']->getWidth());
        $this->assertSame(Alignment::Center, $columns['deadline_withdraw']->getAlignment());
        $this->assertSame(Alignment::Center, $columns['deadline_delivery']->getAlignment());
        $this->assertSame('8rem', $columns['status']->getWidth());
        $this->assertSame('6rem', $columns['tow_yard']->getWidth());
        $this->assertSame('8rem', $columns['condition']->getWidth());
        $this->assertSame('8rem', $columns['value']->getWidth());
        foreach ([
            'vehicle_plate',
            'vehicle_model',
            'origin_city',
            'destination_city',
            'status',
            'condition',
            'value',
        ] as $columnName) {
            $this->assertFalse($columns[$columnName]->isSortable(), "{$columnName} should not be sortable.");
        }
        $this->assertTrue($columns['deadline_withdraw']->isSortable());
        $this->assertTrue($columns['deadline_delivery']->isSortable());
        $this->assertNull($columns['condition']->record(Register::factory()->create())->getState());
        $this->assertTrue($columns['company']->isToggledHidden());
        $this->assertTrue($columns['collected_date']->isToggledHidden());
        $this->assertTrue($columns['delivery_confirmed_at']->isToggledHidden());
        $this->assertTrue($columns['unresolved_removal_imports_exists']->isToggledHidden());
        foreach ([
            'vehicle_plate',
            'vehicle_model',
            'origin_city',
            'destination_city',
            'status',
            'tow_yard',
        ] as $columnName) {
            $this->assertFalse($columns[$columnName]->isIndividuallySearchable());
            $this->assertTrue($columns[$columnName]->isGloballySearchable());
        }
    }

    public function test_register_can_be_created_when_withdrawal_deadline_is_after_delivery_deadline(): void
    {
        Livewire::test(CreateRegister::class)
            ->fillForm([
                'company' => 'millan',
                'vehicle_model' => 'FIAT ARGO 1.3',
                'vehicle_plate' => 'ABC1D23',
                'origin_city' => 'São Paulo',
                'destination_city' => 'Pirapora',
                'vehicle_id' => '1156340',
                'deadline_withdraw' => '2026-09-04',
                'deadline_delivery' => '2026-09-03',
                'status' => 'pending',
                'value' => '500.00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $register = Register::query()->sole();

        $this->assertSame('ABC1D23', $register->vehicle_plate);
        $this->assertSame('2026-09-04', $register->deadline_withdraw->toDateString());
        $this->assertSame('2026-09-03', $register->deadline_delivery->toDateString());
    }

    public function test_analysis_can_be_filtered_and_manually_resolved_from_the_register_list(): void
    {
        $register = Register::factory()->create(['status' => 'analysis']);
        $other = Register::factory()->create(['status' => 'pending']);
        $item = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id, 'message_type' => 'cte_error', 'status' => 'alert',
        ]);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.status', 'analysis')
            ->assertCanSeeTableRecords([$register])
            ->assertCanNotSeeTableRecords([$other])
            ->callTableAction('updateStatusSingle', $register, data: ['status' => 'delivered'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertNotNull($item->refresh()->resolved_at);
    }

    public function test_register_list_displays_deadlines_without_the_year(): void
    {
        $register = Register::factory()->create([
            'deadline_withdraw' => '2026-10-09',
            'deadline_delivery' => '2026-10-19',
        ]);

        Livewire::test(ListRegisters::class)
            ->assertCanSeeTableRecords([$register])
            ->assertSee('09/10')
            ->assertSee('19/10')
            ->assertDontSee('09/10/2026')
            ->assertDontSee('19/10/2026');
    }

    public function test_register_row_actions_use_compact_status_and_pdf_labels(): void
    {
        $registerWithPdf = Register::factory()->create(['pdf_path' => 'registers/removal-letter.pdf']);
        $registerWithoutPdf = Register::factory()->create(['pdf_path' => null]);
        $pdfUrl = Storage::disk('s3')->url($registerWithPdf->pdf_path);

        Livewire::test(ListRegisters::class)
            ->assertTableActionHasLabel('updateStatusSingle', 'Situação', $registerWithPdf)
            ->assertTableActionHasIcon('updateStatusSingle', 'heroicon-o-arrow-path', $registerWithPdf)
            ->assertTableActionHasLabel('openPdf', 'PDF', $registerWithPdf)
            ->assertTableActionHasIcon('openPdf', 'heroicon-o-document-text', $registerWithPdf)
            ->assertTableActionHasUrl('openPdf', $pdfUrl, $registerWithPdf)
            ->assertTableActionShouldOpenUrlInNewTab('openPdf', $registerWithPdf)
            ->assertTableActionVisible('openPdf', $registerWithPdf)
            ->assertTableActionHidden('openPdf', $registerWithoutPdf);
    }

    public function test_vehicle_condition_is_an_inline_select_and_saves_supported_values(): void
    {
        $register = Register::factory()->create();
        $component = Livewire::test(ListRegisters::class);
        $conditionColumn = $component->instance()->getTable()->getColumn('condition');
        $this->assertInstanceOf(SelectColumn::class, $conditionColumn);
        $component->assertTableSelectColumnHasOptions('condition', [
            'ANDA' => 'ANDA',
            'Ñ ANDA' => 'Ñ ANDA',
            'DOC - OK' => 'DOC - OK',
            'AMI - OK' => 'AMI - OK',
            'FIS - OK' => 'FIS - OK',
            'ND - OK' => 'ND - OK',
            'MOTO' => 'MOTO',
        ], $register);
        $this->assertFalse($conditionColumn->isSortable());

        $component->call('updateTableColumnState', 'condition', (string) $register->getKey(), 'DOC - OK');
        $this->assertSame('DOC - OK', $register->refresh()->condition);

        $component->call('updateTableColumnState', 'condition', (string) $register->getKey(), 'Ñ ANDA');
        $this->assertSame('Ñ ANDA', $register->refresh()->condition);

        $component->call('updateTableColumnState', 'condition', (string) $register->getKey(), 'MOTO');
        $this->assertSame('MOTO', $register->refresh()->condition);

        $component->call('updateTableColumnState', 'condition', (string) $register->getKey(), 'OUTRA');
        $this->assertSame('MOTO', $register->refresh()->condition);
    }

    public function test_register_list_uses_the_full_available_page_width(): void
    {
        $this->assertSame(MaxWidth::Full, Livewire::test(ListRegisters::class)->instance()->getMaxContentWidth());
    }

    public function test_register_list_reserves_a_separate_column_for_row_actions(): void
    {
        $spreadsheetCss = file_get_contents(resource_path('css/filament/registers-spreadsheet.css'));

        $this->assertIsString($spreadsheetCss);
        $this->assertStringContainsString('.fi-resource-registers .fi-ta-table .fi-ta-actions-header-cell', $spreadsheetCss);
        $this->assertStringContainsString('.fi-resource-registers .fi-ta-table .fi-ta-actions-cell', $spreadsheetCss);
        $this->assertStringContainsString('min-width: 13rem;', $spreadsheetCss);
    }

    public function test_register_column_filter_controls_stay_within_the_popover(): void
    {
        $spreadsheetCss = file_get_contents(resource_path('css/filament/registers-spreadsheet.css'));

        $this->assertIsString($spreadsheetCss);
        $this->assertStringContainsString('.registers-column-filter-field', $spreadsheetCss);
        $this->assertStringContainsString('.registers-column-filter-label', $spreadsheetCss);
        $this->assertStringContainsString('.registers-column-filter-control', $spreadsheetCss);
        $this->assertStringContainsString('max-width: calc(100vw - 1rem);', $spreadsheetCss);

        Register::factory()->create();
        $response = $this->withoutMiddleware(Authenticate::class)->get('/admin/registers');
        $response->assertOk()
            ->assertSee('registers-column-filter-field', false)
            ->assertSee('registers-column-filter-label', false)
            ->assertSee('registers-column-filter-control', false);
    }

    public function test_vehicle_condition_selector_is_constrained_to_its_table_column(): void
    {
        Register::factory()->create();
        $response = $this->withoutMiddleware(Authenticate::class)->get('/admin/registers');
        $spreadsheetCss = file_get_contents(resource_path('css/filament/registers-spreadsheet.css'));

        $response->assertOk()
            ->assertSee('fi-table-cell-condition', false)
            ->assertSee('fi-ta-select w-full min-w-48', false);
        $this->assertIsString($spreadsheetCss);
        $this->assertStringContainsString('.fi-resource-registers .fi-ta-table .fi-table-cell-condition .fi-ta-select', $spreadsheetCss);
        $this->assertStringContainsString('min-width: 0;', $spreadsheetCss);
        $this->assertStringContainsString('max-width: 100%;', $spreadsheetCss);
    }

    public function test_register_list_has_a_desktop_sidebar_collapse_button(): void
    {
        $this->withoutMiddleware(Authenticate::class)
            ->get('/admin/registers')
            ->assertOk()
            ->assertSee('registers-spreadsheet-column-widths.css', false)
            ->assertSee('ms-auto hidden lg:flex', false)
            ->assertSee('registers-copy-as-image', false);
    }

    public function test_dashboard_does_not_enable_the_desktop_sidebar_collapse_button(): void
    {
        $this->withoutMiddleware(Authenticate::class)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('ms-auto hidden lg:flex', false);
    }

    public function test_register_list_uses_only_the_global_search_for_searchable_columns(): void
    {
        $matching = Register::factory()->create(['vehicle_plate' => 'ABC1234']);
        $other = Register::factory()->create(['vehicle_plate' => 'XYZ9876']);

        Livewire::test(ListRegisters::class)
            ->searchTable('ABC1234')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_register_list_can_filter_a_date_column_by_range(): void
    {
        $before = Register::factory()->create(['deadline_withdraw' => '2026-09-10']);
        $firstInRange = Register::factory()->create(['deadline_withdraw' => '2026-09-20']);
        $lastInRange = Register::factory()->create(['deadline_withdraw' => '2026-09-30']);
        $after = Register::factory()->create(['deadline_withdraw' => '2026-10-01']);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.deadline_withdraw.from', '2026-09-20')
            ->set('columnFilters.deadline_withdraw.until', '2026-09-30')
            ->assertCanSeeTableRecords([$firstInRange, $lastInRange])
            ->assertCanNotSeeTableRecords([$before, $after]);
    }

    public function test_register_list_can_filter_the_delivery_deadline_by_range(): void
    {
        $before = Register::factory()->create(['deadline_delivery' => '2026-09-10']);
        $matching = Register::factory()->create(['deadline_delivery' => '2026-09-20']);
        $after = Register::factory()->create(['deadline_delivery' => '2026-10-01']);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.deadline_delivery.from', '2026-09-20')
            ->set('columnFilters.deadline_delivery.until', '2026-09-30')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$before, $after]);
    }

    public function test_register_cell_color_can_be_changed_and_reset_inline(): void
    {
        $register = Register::factory()->create();
        $legacyBlack = Register::factory()->create(['vehicle_plate_color' => 'black']);

        Livewire::test(ListRegisters::class)
            ->mountTableAction('editVehiclePlateColor', $legacyBlack)
            ->assertTableActionDataSet(['color' => 'automatic']);

        Livewire::test(ListRegisters::class)
            ->callTableAction('editVehiclePlateColor', $register, ['color' => 'blue'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('blue', $register->refresh()->vehicle_plate_color);

        Livewire::test(ListRegisters::class)
            ->callTableAction('editVehiclePlateColor', $register, ['color' => 'automatic'])
            ->assertHasNoTableActionErrors();

        $this->assertNull($register->refresh()->vehicle_plate_color);
    }

    public function test_register_cell_colors_match_their_operational_palettes(): void
    {
        $register = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'vehicle_model_color' => 'red',
            'origin_city_color' => 'red',
            'destination_city_color' => 'yellow',
            'deadline_withdraw_color' => 'red',
            'deadline_delivery_color' => 'red',
        ]);
        $automaticPastDeadlines = Register::factory()->create([
            'deadline_withdraw' => '2026-09-09',
            'deadline_delivery' => '2026-09-17',
        ]);
        $manuallyUnmarkedDeadlines = Register::factory()->create([
            'deadline_withdraw' => '2026-10-20',
            'deadline_withdraw_color' => 'black',
            'deadline_delivery' => '2026-10-25',
            'deadline_delivery_color' => 'black',
        ]);
        $columns = Livewire::test(ListRegisters::class)->instance()->getTable()->getColumns();

        foreach ([
            'vehicle_plate' => 'info',
            'vehicle_model' => 'danger',
            'origin_city' => 'danger',
            'destination_city' => 'warning',
            'deadline_withdraw' => 'danger',
            'deadline_delivery' => 'danger',
        ] as $columnName => $expectedColor) {
            $column = $columns[$columnName]->record($register);

            $this->assertSame($expectedColor, $column->getColor($column->getState()));
        }

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $automaticColumn = $columns[$columnName]->record($automaticPastDeadlines);
            $this->assertSame('danger', $automaticColumn->getColor($automaticColumn->getState()));

            $unmarkedColumn = $columns[$columnName]->record($manuallyUnmarkedDeadlines);
            $this->assertSame('gray', $unmarkedColumn->getColor($unmarkedColumn->getState()));
        }
    }

    public function test_automatic_deadline_colors_respect_dates_statuses_and_manual_choices(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $columns = Livewire::test(ListRegisters::class)->instance()->getTable()->getColumns();

        foreach (RegisterStatusEnum::cases() as $status) {
            $canHighlight = in_array($status, [
                RegisterStatusEnum::PENDING,
                RegisterStatusEnum::AVAILABLE,
                RegisterStatusEnum::PENDING_DAILY_RATES,
            ], true);

            foreach ([
                ['2026-10-18', 'danger'],
                ['2026-10-19 23:59:59', 'danger'],
                ['2026-10-20', 'gray'],
                [null, 'gray'],
            ] as [$deadline, $dueColor]) {
                foreach ([null, 'red', 'black'] as $manualColor) {
                    $register = Register::factory()->make([
                        'status' => $status,
                        'deadline_withdraw' => $deadline,
                        'deadline_delivery' => $deadline,
                        'deadline_withdraw_color' => $manualColor,
                        'deadline_delivery_color' => $manualColor,
                    ]);
                    $expectedColor = match ($manualColor) {
                        'red' => 'danger',
                        'black' => 'gray',
                        default => $canHighlight ? $dueColor : 'gray',
                    };

                    foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
                        $column = $columns[$columnName]->record($register);
                        $this->assertSame($expectedColor, $column->getColor($column->getState()), $status->value.' '.$columnName.' '.$deadline.' '.($manualColor ?? 'automatic'));
                    }
                }
            }
        }

        $mixedDeadlines = Register::factory()->make([
            'deadline_withdraw' => '2026-10-20',
            'deadline_delivery' => '2026-10-18',
        ]);
        $withdraw = $columns['deadline_withdraw']->record($mixedDeadlines);
        $delivery = $columns['deadline_delivery']->record($mixedDeadlines);

        $this->assertSame('gray', $withdraw->getColor($withdraw->getState()));
        $this->assertSame('danger', $delivery->getColor($delivery->getState()));
    }

    public function test_deadline_color_filters_and_exports_match_the_visible_colors(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $red = collect();
        $unmarked = collect();
        $operationalUnmarked = collect();

        foreach (RegisterStatusEnum::cases() as $status) {
            foreach (['2026-10-18', '2026-10-19 23:59:59', '2026-10-20'] as $deadline) {
                $register = Register::factory()->create([
                    'status' => $status,
                    'deadline_withdraw' => $deadline,
                    'deadline_delivery' => $deadline,
                ]);

                if (in_array($status, [RegisterStatusEnum::PENDING, RegisterStatusEnum::AVAILABLE, RegisterStatusEnum::PENDING_DAILY_RATES], true) && $deadline !== '2026-10-20') {
                    $red->push($register);
                } else {
                    $unmarked->push($register);
                }

                if (in_array($status, [
                    RegisterStatusEnum::COLLECTED,
                    RegisterStatusEnum::PENDING,
                    RegisterStatusEnum::AVAILABLE,
                    RegisterStatusEnum::PENDING_DAILY_RATES,
                ], true) && ! $red->contains($register)) {
                    $operationalUnmarked->push($register);
                }
            }
        }

        $historicalManualRed = Register::factory()->create([
            'status' => 'paid',
            'deadline_withdraw' => '2026-10-20',
            'deadline_delivery' => '2026-10-20',
            'deadline_withdraw_color' => 'red',
            'deadline_delivery_color' => 'red',
        ]);
        $legacyBlack = Register::factory()->create([
            'deadline_withdraw' => '2026-10-18',
            'deadline_delivery' => '2026-10-18',
            'deadline_withdraw_color' => 'black',
            'deadline_delivery_color' => 'black',
        ]);
        $unmarked->push($legacyBlack);
        $operationalUnmarked->push($legacyBlack);

        foreach (['deadline_withdraw_color', 'deadline_delivery_color'] as $attribute) {
            $component = Livewire::test(ListRegisters::class)
                ->set('columnFilters', [$attribute => 'red'])
                ->assertCanSeeTableRecords($red->all())
                ->assertCanNotSeeTableRecords([...$unmarked, $historicalManualRed]);

            $this->assertEqualsCanonicalizing($red->pluck('id')->all(), $component->instance()->getTableQueryForExport()->pluck('registers.id')->all());

            $component->set('columnFilters', [$attribute => 'automatic'])
                ->assertCanSeeTableRecords($operationalUnmarked->all())
                ->assertCanNotSeeTableRecords([...$red, $historicalManualRed]);

            $this->assertEqualsCanonicalizing($operationalUnmarked->pluck('id')->all(), $component->instance()->getTableQueryForExport()->pluck('registers.id')->all());
        }
    }

    public function test_status_changes_remove_only_automatic_deadline_colors(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $automatic = Register::factory()->create([
            'deadline_withdraw' => '2026-10-18',
            'deadline_delivery' => '2026-10-19',
        ]);
        $manual = Register::factory()->create([
            'deadline_withdraw' => '2026-10-18',
            'deadline_delivery' => '2026-10-19',
            'deadline_withdraw_color' => 'red',
            'deadline_delivery_color' => 'red',
        ]);
        $component = Livewire::test(ListRegisters::class);
        $columns = $component->instance()->getTable()->getColumns();

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $column = $columns[$columnName]->record($automatic);
            $this->assertSame('danger', $column->getColor($column->getState()));
        }

        $component->callTableAction('updateStatusSingle', $automatic, ['status' => 'paid'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('updateStatusSingle', $manual, ['status' => 'paid'])
            ->assertHasNoTableActionErrors();
        $automatic->refresh();
        $manual->refresh();

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $column = $columns[$columnName]->record($automatic);
            $this->assertSame('gray', $column->getColor($column->getState()));
            $this->assertNull($automatic->{$columnName.'_color'});

            $column->record($manual);
            $this->assertSame('danger', $column->getColor($column->getState()));
            $this->assertSame('red', $manual->{$columnName.'_color'});
        }

        $component->callTableBulkAction('copyAsImage', [$automatic, $manual])
            ->assertDispatched('registers-copy-as-image', function (string $event, array $params) use ($automatic, $manual): bool {
                $rows = collect($params['rows'])->keyBy('plate');

                return $rows[$automatic->vehicle_plate]['colors']['deadline_withdraw_color'] === null
                    && $rows[$automatic->vehicle_plate]['colors']['deadline_delivery_color'] === null
                    && $rows[$manual->vehicle_plate]['colors']['deadline_withdraw_color'] === 'red'
                    && $rows[$manual->vehicle_plate]['colors']['deadline_delivery_color'] === 'red';
            });

        $component->callTableAction('updateStatusSingle', $automatic, ['status' => 'available'])
            ->assertHasNoTableActionErrors();
        $automatic->refresh();

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $column = $columns[$columnName]->record($automatic);
            $this->assertSame('danger', $column->getColor($column->getState()));
            $this->assertNull($automatic->{$columnName.'_color'});
        }
    }

    public function test_deadline_alerts_follow_the_calendar_day_in_sao_paulo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 23:30:00', 'America/Sao_Paulo'));
        $register = Register::factory()->create([
            'deadline_withdraw' => '2026-10-20',
            'deadline_delivery' => '2026-10-20',
        ]);
        $component = Livewire::test(ListRegisters::class);
        $columns = $component->instance()->getTable()->getColumns();

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $column = $columns[$columnName]->record($register);
            $this->assertSame('gray', $column->getColor($column->getState()));
            $component->set('columnFilters', [$columnName.'_color' => 'red'])->assertCanNotSeeTableRecords([$register]);
        }

        $this->travelTo(Carbon::parse('2026-10-20 00:01:00', 'America/Sao_Paulo'));

        foreach (['deadline_withdraw', 'deadline_delivery'] as $columnName) {
            $column = $columns[$columnName]->record($register);
            $this->assertSame('danger', $column->getColor($column->getState()));
            $component->set('columnFilters', [$columnName.'_color' => 'red'])->assertCanSeeTableRecords([$register]);
        }
    }

    public function test_register_operational_colors_can_be_filtered_by_column(): void
    {
        $colorFilters = [
            ['column' => 'vehicle_plate_color', 'color' => 'blue'],
            ['column' => 'vehicle_model_color', 'color' => 'red'],
            ['column' => 'origin_city_color', 'color' => 'red'],
            ['column' => 'destination_city_color', 'color' => 'yellow'],
            ['column' => 'deadline_withdraw_color', 'color' => 'red'],
            ['column' => 'deadline_delivery_color', 'color' => 'red'],
        ];

        foreach ($colorFilters as $index => $filter) {
            $matching = Register::factory()->create([
                'vehicle_plate' => 'COL'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                $filter['column'] => $filter['color'],
            ]);
            $otherAttributes = [
                'vehicle_plate' => 'OTH'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            ];

            if (isset(RegisterResource::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$filter['column']])) {
                $otherAttributes[$filter['column']] = 'black';
            }

            $other = Register::factory()->create($otherAttributes);

            Livewire::test(ListRegisters::class)
                ->set('columnFilters.'.$filter['column'], $filter['color'])
                ->assertCanSeeTableRecords([$matching])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    public function test_color_filters_scope_records_and_exports_to_the_four_operational_statuses(): void
    {
        $operational = collect();
        $historical = collect();

        foreach ([
            'pending',
            'available',
            'collected',
            'pending daily rates',
        ] as $status) {
            $operational->push(Register::factory()->create([
                'status' => $status,
                'vehicle_plate_color' => 'blue',
            ]));
        }

        foreach (['paid', 'delivered', 'cancelled', 'invoiced', 'analysis'] as $status) {
            $historical->push(Register::factory()->create([
                'status' => $status,
                'vehicle_plate_color' => 'blue',
            ]));
        }

        $component = Livewire::test(ListRegisters::class)
            ->set('columnFilters.vehicle_plate_color', 'blue')
            ->assertCanSeeTableRecords($operational->all())
            ->assertCanNotSeeTableRecords($historical->all());

        $this->assertEqualsCanonicalizing(
            $operational->pluck('id')->all(),
            $component->instance()->getTableQueryForExport()->pluck('registers.id')->all(),
        );

        foreach ([...$operational, ...$historical] as $register) {
            $this->assertSame('blue', $register->refresh()->vehicle_plate_color);
        }
    }

    public function test_city_filters_scope_matching_rows_to_the_four_operational_statuses(): void
    {
        $pendingOrigin = Register::factory()->create(['status' => 'pending', 'origin_city' => 'Ourinhos']);
        $paidOrigin = Register::factory()->create(['status' => 'paid', 'origin_city' => 'Ourinhos']);
        $availableDestination = Register::factory()->create([
            'status' => 'available',
            'origin_city' => 'Bauru',
            'destination_city' => 'Santos',
        ]);
        $invoicedDestination = Register::factory()->create([
            'status' => 'invoiced',
            'origin_city' => 'Bauru',
            'destination_city' => 'Santos',
        ]);

        $component = Livewire::test(ListRegisters::class)
            ->set('columnFilters.origin_cities', ['Ourinhos'])
            ->assertCanSeeTableRecords([$pendingOrigin])
            ->assertCanNotSeeTableRecords([$paidOrigin, $availableDestination, $invoicedDestination])
            ->call('clearColumnFilter', 'origin_city')
            ->assertCanSeeTableRecords([$pendingOrigin, $paidOrigin, $availableDestination, $invoicedDestination])
            ->set('columnFilters.destination_cities', ['Santos'])
            ->assertCanSeeTableRecords([$availableDestination])
            ->assertCanNotSeeTableRecords([$pendingOrigin, $paidOrigin, $invoicedDestination]);

        $this->assertEqualsCanonicalizing(
            [$availableDestination->id],
            $component->instance()->getTableQueryForExport()->pluck('registers.id')->all(),
        );
    }

    public function test_unset_invalid_and_empty_color_or_city_filters_preserve_historical_rows(): void
    {
        $historical = collect();

        foreach (['paid', 'delivered', 'cancelled', 'invoiced', 'analysis'] as $status) {
            $historical->push(Register::factory()->create(['status' => $status]));
        }

        $component = Livewire::test(ListRegisters::class)
            ->assertCanSeeTableRecords($historical->all());

        foreach (['not-a-color', ''] as $color) {
            $component->set('columnFilters.vehicle_plate_color', $color)
                ->assertCanSeeTableRecords($historical->all());
        }

        $component->set('columnFilters.vehicle_plate_color', null)
            ->set('columnFilters.origin_cities', ['unknown origin'])
            ->set('columnFilters.destination_cities', ['unknown destination'])
            ->assertCanSeeTableRecords($historical->all());

        $component->set('columnFilters.origin_cities', [])
            ->set('columnFilters.destination_cities', [])
            ->set('columnFilters.status', 'paid')
            ->assertCanSeeTableRecords([$historical->first()])
            ->assertCanNotSeeTableRecords($historical->slice(1)->all());
    }

    public function test_origin_filter_combines_multiple_cities_with_color_and_other_filters(): void
    {
        $osvaldoCruz = Register::factory()->create([
            'origin_city' => 'Osvaldo Cruz',
            'origin_city_color' => 'red',
        ]);
        $ourinhos = Register::factory()->create([
            'origin_city' => 'Ourinhos',
            'origin_city_color' => 'red',
        ]);
        $otherCity = Register::factory()->create([
            'origin_city' => 'Santos',
            'origin_city_color' => 'red',
        ]);
        $otherColor = Register::factory()->create([
            'origin_city' => 'Osvaldo Cruz',
        ]);
        $otherStatus = Register::factory()->create([
            'origin_city' => 'Osvaldo Cruz',
            'origin_city_color' => 'red',
            'status' => 'paid',
        ]);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.origin_city_color', 'red')
            ->set('columnFilters.origin_cities', ['Osvaldo Cruz', 'Ourinhos'])
            ->assertCanSeeTableRecords([$osvaldoCruz, $ourinhos])
            ->assertCanNotSeeTableRecords([$otherCity, $otherColor, $otherStatus])
            ->call('clearColumnFilter', 'origin_city')
            ->assertSet('columnFilters.origin_city_color', null)
            ->assertSet('columnFilters.origin_cities', [])
            ->assertCanSeeTableRecords([$otherCity, $otherColor, $otherStatus]);
    }

    public function test_origin_city_filter_toggles_each_city_independently(): void
    {
        $osvaldoCruz = Register::factory()->create(['origin_city' => 'Osvaldo Cruz']);
        $ourinhos = Register::factory()->create(['origin_city' => 'Ourinhos']);

        $component = Livewire::test(ListRegisters::class)
            ->call('toggleOriginCityFilter', 'Osvaldo Cruz')
            ->assertSet('columnFilters.origin_cities', ['Osvaldo Cruz'])
            ->assertCanSeeTableRecords([$osvaldoCruz])
            ->assertCanNotSeeTableRecords([$ourinhos])
            ->assertSeeHtml('value="Ourinhos"');

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getOriginCityFilterOptions());

        $component
            ->call('toggleOriginCityFilter', 'Ourinhos')
            ->assertSet('columnFilters.origin_cities', ['Osvaldo Cruz', 'Ourinhos'])
            ->assertCanSeeTableRecords([$osvaldoCruz, $ourinhos])
            ->call('toggleOriginCityFilter', 'Osvaldo Cruz')
            ->assertSet('columnFilters.origin_cities', ['Ourinhos'])
            ->assertCanSeeTableRecords([$ourinhos])
            ->assertCanNotSeeTableRecords([$osvaldoCruz]);
    }

    public function test_destination_city_filter_toggles_cities_independently_and_combines_with_color(): void
    {
        $ourinhos = Register::factory()->create([
            'destination_city' => 'Ourinhos',
            'destination_city_color' => 'red',
            'insurance' => 'Seguradora A',
        ]);
        $osvaldoCruz = Register::factory()->create([
            'destination_city' => 'Osvaldo Cruz',
            'destination_city_color' => 'red',
        ]);
        $otherColor = Register::factory()->create(['destination_city' => 'Ourinhos']);

        $component = Livewire::test(ListRegisters::class)
            ->set('columnFilters.destination_city_color', 'red')
            ->call('toggleDestinationCityFilter', 'Ourinhos')
            ->assertSet('columnFilters.destination_cities', ['Ourinhos'])
            ->assertCanSeeTableRecords([$ourinhos])
            ->assertCanNotSeeTableRecords([$osvaldoCruz, $otherColor])
            ->assertSeeHtml('value="Osvaldo Cruz"');

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getDestinationCityFilterOptions());

        $component->call('toggleDestinationCityFilter', 'Osvaldo Cruz')
            ->assertSet('columnFilters.destination_cities', ['Ourinhos', 'Osvaldo Cruz'])
            ->assertCanSeeTableRecords([$ourinhos, $osvaldoCruz])
            ->call('toggleDestinationCityFilter', 'Ourinhos')
            ->assertSet('columnFilters.destination_cities', ['Osvaldo Cruz'])
            ->assertCanSeeTableRecords([$osvaldoCruz])
            ->call('clearColumnFilter', 'destination_city')
            ->assertSet('columnFilters.destination_city_color', null)
            ->assertSet('columnFilters.destination_cities', [])
            ->assertCanSeeTableRecords([$ourinhos, $osvaldoCruz, $otherColor]);
    }

    public function test_destination_city_options_follow_origin_selection_and_origin_options_follow_destination_selection(): void
    {
        Register::factory()->create(['origin_city' => 'Campinas', 'destination_city' => 'Santos']);
        Register::factory()->create(['origin_city' => 'Campinas', 'destination_city' => 'Bauru']);
        Register::factory()->create(['origin_city' => 'Ourinhos', 'destination_city' => 'Assis']);

        $component = Livewire::test(ListRegisters::class)
            ->call('toggleOriginCityFilter', 'Campinas')
            ->assertSeeHtml('value="Bauru"')
            ->assertSeeHtml('value="Santos"')
            ->assertDontSeeHtml('value="Assis"');

        $this->assertSame(['Bauru', 'Santos'], $component->instance()->getDestinationCityFilterOptions());

        $component->call('toggleDestinationCityFilter', 'Santos')
            ->assertCanSeeTableRecords(Register::query()->where('origin_city', 'Campinas')->where('destination_city', 'Santos')->get())
            ->assertDontSeeHtml('value="Ourinhos"');

        $this->assertSame(['Campinas'], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_destination_city_options_respect_search_and_other_filters_across_pages(): void
    {
        Register::factory()->count(25)->create([
            'destination_city' => 'Ourinhos',
            'vehicle_model' => 'CIVIC',
            'destination_city_color' => 'red',
            'status' => 'pending',
            'deadline_withdraw' => '2026-10-01',
        ]);
        $secondPageRegister = Register::factory()->create([
            'destination_city' => 'Osvaldo Cruz',
            'vehicle_model' => 'CIVIC',
            'destination_city_color' => 'red',
            'status' => 'pending',
            'deadline_withdraw' => '2026-10-02',
        ]);
        Register::factory()->create([
            'destination_city' => 'Santos',
            'vehicle_model' => 'COROLLA',
            'destination_city_color' => 'red',
            'status' => 'pending',
            'deadline_withdraw' => '2026-10-02',
        ]);

        $component = Livewire::test(ListRegisters::class)
            ->filterTable('company', 'copart')
            ->searchTable('CIVIC')
            ->set('columnFilters', [
                'destination_city_color' => 'red',
                'deadline_withdraw' => ['from' => '2026-10-01', 'until' => '2026-10-03'],
            ])
            ->sortTable('deadline_withdraw', 'asc')
            ->assertCanNotSeeTableRecords([$secondPageRegister])
            ->assertSeeHtml('value="Osvaldo Cruz"')
            ->assertSeeHtml('value="Ourinhos"')
            ->assertDontSeeHtml('value="Santos"');

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getDestinationCityFilterOptions());
    }

    public function test_destination_city_options_only_include_operational_statuses_and_selected_city_can_be_removed(): void
    {
        $retired = Register::factory()->create(['destination_city' => 'Ourinhos', 'status' => 'paid']);
        $eligible = Register::factory()->create(['destination_city' => 'Osvaldo Cruz', 'status' => 'pending']);
        Register::factory()->create(['destination_city' => 'Bauru', 'status' => 'collected']);
        Register::factory()->create(['destination_city' => 'Campinas', 'status' => 'available']);
        Register::factory()->create(['destination_city' => 'Assis', 'status' => 'pending daily rates']);
        Register::factory()->create(['destination_city' => 'Santos', 'status' => 'delivered']);

        $component = Livewire::test(ListRegisters::class)
            ->set('columnFilters.destination_cities', ['Ourinhos'])
            ->assertCanNotSeeTableRecords([$retired, $eligible])
            ->assertDontSeeHtml('value="Ourinhos"');

        $this->assertSame(['Assis', 'Bauru', 'Campinas', 'Osvaldo Cruz'], $component->instance()->getDestinationCityFilterOptions());

        $component->call('toggleDestinationCityFilter', 'Ourinhos')
            ->assertSet('columnFilters.destination_cities', [])
            ->assertCanSeeTableRecords([$retired, $eligible]);
    }

    public function test_destination_city_filter_options_are_dynamic_distinct_and_sorted(): void
    {
        Register::factory()->create(['destination_city' => 'Ourinhos']);
        Register::factory()->create(['destination_city' => 'Bauru']);
        Register::factory()->create(['destination_city' => 'Ourinhos']);
        Register::factory()->create(['destination_city' => '']);

        $this->assertSame(
            ['Bauru', 'Ourinhos'],
            RegisterResource::spreadsheetDestinationCityFilterOptions(),
        );
    }

    public function test_origin_city_options_follow_search_and_other_table_filters(): void
    {
        $matchingAttributes = [
            'company' => 'copart',
            'vehicle_model' => 'CIVIC',
            'vehicle_plate_color' => 'blue',
            'origin_city_color' => 'red',
            'status' => 'pending',
            'deadline_withdraw' => '2026-10-20',
        ];

        foreach ([
            ['origin_city' => 'Ourinhos'],
            ['origin_city' => 'Osvaldo Cruz'],
            ['origin_city' => 'Santos', 'vehicle_plate_color' => null],
            ['origin_city' => 'Bauru', 'status' => 'paid'],
            ['origin_city' => 'Campinas', 'company' => 'millan'],
            ['origin_city' => 'Assis', 'vehicle_model' => 'COROLLA'],
            ['origin_city' => 'Lins', 'origin_city_color' => null],
            ['origin_city' => 'Avare', 'deadline_withdraw' => '2026-11-20'],
        ] as $attributes) {
            Register::factory()->create([...$matchingAttributes, ...$attributes]);
        }

        $component = Livewire::test(ListRegisters::class)
            ->filterTable('company', 'copart')
            ->searchTable('CIVIC')
            ->set('columnFilters', [
                'vehicle_plate_color' => 'blue',
                'origin_city_color' => 'red',
                'status' => 'pending',
                'deadline_withdraw' => ['from' => '2026-10-19', 'until' => '2026-10-21'],
            ])
            ->assertSeeHtml('value="Ourinhos"')
            ->assertSeeHtml('value="Osvaldo Cruz"');

        foreach (['Santos', 'Bauru', 'Campinas', 'Assis', 'Lins', 'Avare'] as $city) {
            $component->assertDontSeeHtml('value="'.$city.'"');
        }

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getOriginCityFilterOptions());

        $component->searchTable('SEM RESULTADOS')->assertSee('Limpar filtros das colunas');

        $this->assertSame([], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_origin_city_options_include_matches_from_other_pages(): void
    {
        Register::factory()->count(25)->create([
            'origin_city' => 'Ourinhos',
            'deadline_withdraw' => '2026-10-01',
        ]);
        $secondPageRegister = Register::factory()->create([
            'origin_city' => 'Osvaldo Cruz',
            'deadline_withdraw' => '2026-10-02',
        ]);

        $component = Livewire::test(ListRegisters::class)
            ->sortTable('deadline_withdraw', 'asc')
            ->assertCanNotSeeTableRecords([$secondPageRegister])
            ->assertSeeHtml('value="Osvaldo Cruz"');

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_origin_city_options_only_include_the_four_operational_statuses(): void
    {
        $includedCities = [
            'collected' => 'Bauru',
            'pending' => 'Ourinhos',
            'available' => 'Osvaldo Cruz',
            'pending daily rates' => 'Campinas',
        ];
        $excludedCities = [
            'paid' => 'Santos',
            'delivered' => 'Assis',
            'cancelled' => 'Lins',
            'invoiced' => 'Marilia',
            'analysis' => 'Avare',
        ];
        $registers = collect();

        foreach ([...$includedCities, ...$excludedCities] as $status => $city) {
            $registers->push(Register::factory()->create(['status' => $status, 'origin_city' => $city]));
        }

        Register::factory()->create(['status' => 'pending', 'origin_city' => 'Araraquara']);
        $sharedPaid = Register::factory()->create(['status' => 'paid', 'origin_city' => 'Araraquara']);

        $component = Livewire::test(ListRegisters::class)
            ->assertCanSeeTableRecords($registers->all())
            ->assertSeeHtml('value="Araraquara"');

        foreach ($includedCities as $city) {
            $component->assertSeeHtml('value="'.$city.'"');
        }

        foreach ($excludedCities as $city) {
            $component->assertDontSeeHtml('value="'.$city.'"');
        }

        $this->assertSame(['Araraquara', 'Bauru', 'Campinas', 'Osvaldo Cruz', 'Ourinhos'], $component->instance()->getOriginCityFilterOptions());

        $component->set('columnFilters.status', 'paid')
            ->assertCanSeeTableRecords([$sharedPaid])
            ->assertDontSeeHtml('value="Araraquara"');

        $this->assertSame([], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_excluded_status_cities_are_not_restored_by_existing_city_selections(): void
    {
        $retired = Register::factory()->create(['origin_city' => 'Ourinhos', 'status' => 'paid']);
        $eligible = Register::factory()->create(['origin_city' => 'Osvaldo Cruz', 'status' => 'pending']);
        $unrelated = Register::factory()->create(['origin_city' => 'Lins', 'status' => 'paid']);

        $component = Livewire::test(ListRegisters::class)
            ->set('columnFilters.origin_cities', ['Ourinhos'])
            ->assertCanNotSeeTableRecords([$retired, $eligible, $unrelated])
            ->assertDontSeeHtml('value="Ourinhos"');

        $this->assertSame(['Osvaldo Cruz'], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_selected_origin_city_can_be_removed_when_other_filters_exclude_its_records(): void
    {
        $pending = Register::factory()->create(['origin_city' => 'Ourinhos', 'status' => 'pending']);
        $collected = Register::factory()->create(['origin_city' => 'Osvaldo Cruz', 'status' => 'collected']);

        $component = Livewire::test(ListRegisters::class)
            ->call('toggleOriginCityFilter', 'Ourinhos')
            ->call('toggleOriginCityFilter', 'Osvaldo Cruz')
            ->set('columnFilters.status', 'collected')
            ->assertCanSeeTableRecords([$collected])
            ->assertCanNotSeeTableRecords([$pending])
            ->assertSet('columnFilters.origin_cities', ['Ourinhos', 'Osvaldo Cruz'])
            ->assertSeeHtml('value="Ourinhos"');

        $this->assertSame(['Osvaldo Cruz', 'Ourinhos'], $component->instance()->getOriginCityFilterOptions());

        $component->call('toggleOriginCityFilter', 'Ourinhos')->assertCanSeeTableRecords([$collected]);

        $this->assertSame(['Osvaldo Cruz'], $component->instance()->getOriginCityFilterOptions());
    }

    public function test_origin_filter_options_are_dynamic_distinct_and_sorted(): void
    {
        Register::factory()->create(['origin_city' => 'Ourinhos']);
        Register::factory()->create(['origin_city' => 'Osvaldo Cruz']);
        Register::factory()->create(['origin_city' => 'Ourinhos']);
        Register::factory()->create(['origin_city' => '']);

        $this->assertSame(
            ['Osvaldo Cruz', 'Ourinhos'],
            RegisterResource::spreadsheetOriginCityFilterOptions(),
        );
    }

    public function test_register_column_filter_menus_are_attached_to_visible_headers(): void
    {
        Register::factory()->create(['tow_yard' => 'Pátio central']);
        Register::factory()->create(['origin_city' => 'Osvaldo Cruz', 'destination_city' => 'Ourinhos']);
        $response = $this->withoutMiddleware(Authenticate::class)->get('/admin/registers');
        $response->assertOk();
        preg_match_all('/wire:model[^\s>]+="[^"]+"/', $response->getContent(), $modelBindings);
        $this->assertContains('wire:model.live="columnFilters.vehicle_plate_color"', $modelBindings[0]);
        $this->assertContains('wire:model.live="columnFilters.status"', $modelBindings[0]);
        $this->assertContains('wire:model.live="columnFilters.deadline_withdraw.from"', $modelBindings[0]);
        $this->assertContains('wire:model.live="columnFilters.tow_yard"', $modelBindings[0]);
        $this->assertStringContainsString('value="Pátio central"', $response->getContent());
        $this->assertStringContainsString('value="Ourinhos"', $response->getContent());
        $this->assertStringContainsString('x-bind:checked=', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/wire:model.live="columnFilters.origin_cities"/', $response->getContent());
        preg_match_all('/wire:click="[^"]*toggleOriginCityFilter\(/', $response->getContent(), $originCityActions);
        $this->assertCount(2, $originCityActions[0]);
        preg_match_all('/wire:key="origin-city-filter-([^"]+)"/', $response->getContent(), $originCityKeys);
        $this->assertCount(2, $originCityKeys[1]);
        $this->assertCount(2, array_unique($originCityKeys[1]));
        preg_match_all('/wire:click="[^"]*toggleDestinationCityFilter\(/', $response->getContent(), $destinationCityActions);
        $this->assertCount(2, $destinationCityActions[0]);
        preg_match_all('/wire:key="destination-city-filter-([^"]+)"/', $response->getContent(), $destinationCityKeys);
        $this->assertCount(2, $destinationCityKeys[1]);
        $this->assertCount(2, array_unique($destinationCityKeys[1]));

        preg_match_all('/data-register-column-filter="([^"]+)"/', $response->getContent(), $filterableColumns);
        $this->assertSame([
            'vehicle_plate',
            'vehicle_model',
            'origin_city',
            'destination_city',
            'deadline_withdraw',
            'deadline_delivery',
            'status',
            'tow_yard',
        ], $filterableColumns[1]);
        $this->assertSame(RegisterResource::SPREADSHEET_FILTERABLE_COLUMNS, $filterableColumns[1]);

        $component = Livewire::test(ListRegisters::class);
        $this->assertSame([
            'company',
            'cte_autorizado',
            'payment_deferred_at_range',
            'fipe_value_range',
        ], array_keys($component->instance()->getTable()->getFilters()));
        $this->assertSame('Buscar placa, modelo ou cidade', $component->instance()->getTable()->getSearchPlaceholder());
    }

    public function test_register_column_color_filter_options_offer_only_meaningful_choices(): void
    {
        $plateOptions = RegisterResource::spreadsheetColumnColorOptions('vehicle_plate');

        $this->assertSame('Automático — sem marcação', $plateOptions['automatic']);
        $this->assertSame('Azul — região afastada', $plateOptions['blue']);
        $this->assertArrayNotHasKey('black', $plateOptions);

        foreach ([
            'vehicle_model',
            'origin_city',
            'destination_city',
            'deadline_withdraw',
            'deadline_delivery',
        ] as $column) {
            $options = RegisterResource::spreadsheetColumnColorOptions($column);

            $this->assertArrayHasKey('automatic', $options);
            $this->assertArrayNotHasKey('black', $options);
        }

        $filters = Livewire::test(ListRegisters::class)->instance()->getTable()->getFilters();
        $this->assertArrayNotHasKey('status', $filters);
        $this->assertArrayNotHasKey('plate_color', $filters);
    }

    public function test_automatic_plate_filter_includes_unmarked_and_legacy_black_cells(): void
    {
        $unmarked = Register::factory()->create(['vehicle_plate_color' => null]);
        $legacyBlack = Register::factory()->create(['vehicle_plate_color' => 'black']);
        $blue = Register::factory()->create(['vehicle_plate_color' => 'blue']);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.vehicle_plate_color', 'automatic')
            ->assertCanSeeTableRecords([$unmarked, $legacyBlack])
            ->assertCanNotSeeTableRecords([$blue]);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.vehicle_plate_color', 'blue')
            ->assertCanSeeTableRecords([$blue])
            ->assertCanNotSeeTableRecords([$unmarked, $legacyBlack]);
    }

    public function test_register_column_filters_can_be_combined(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));

        $matching = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'pending',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-20',
            'deadline_withdraw_color' => 'red',
            'value' => 1200,
        ]);
        $wrongColor = Register::factory()->create([
            'status' => 'pending',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-20',
            'deadline_withdraw_color' => 'red',
            'value' => 1200,
        ]);
        $futureDeadline = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'pending',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-20',
            'deadline_withdraw_color' => null,
            'value' => 1200,
        ]);
        $wrongStatus = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'paid',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-20',
            'value' => 1200,
        ]);
        $wrongYard = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'pending',
            'tow_yard' => 'Pátio Santos',
            'deadline_withdraw' => '2026-10-20',
            'value' => 1200,
        ]);
        $wrongDate = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'pending',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-01',
            'deadline_withdraw_color' => 'red',
            'value' => 1200,
        ]);
        $wrongValue = Register::factory()->create([
            'vehicle_plate_color' => 'blue',
            'status' => 'pending',
            'tow_yard' => 'Pátio Campinas',
            'deadline_withdraw' => '2026-10-20',
            'value' => 3000,
        ]);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.vehicle_plate_color', 'blue')
            ->set('columnFilters.deadline_withdraw_color', 'red')
            ->set('columnFilters.status', 'pending')
            ->set('columnFilters.tow_yard', 'Pátio Campinas')
            ->set('columnFilters.deadline_withdraw.from', '2026-10-10')
            ->set('columnFilters.deadline_withdraw.until', '2026-10-30')
            ->set('columnFilters.value.minimum', '1000')
            ->set('columnFilters.value.maximum', '2000')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$wrongColor, $futureDeadline, $wrongStatus, $wrongYard, $wrongDate, $wrongValue]);
    }

    public function test_column_filters_can_be_cleared_when_the_filtered_table_is_empty(): void
    {
        $register = Register::factory()->create(['vehicle_plate_color' => 'red']);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.vehicle_plate_color', 'blue')
            ->assertCanNotSeeTableRecords([$register])
            ->assertSee('Limpar filtros das colunas')
            ->call('clearColumnFilters')
            ->assertDontSee('Limpar filtros das colunas')
            ->assertCanSeeTableRecords([$register]);
    }

    public function test_selected_registers_can_be_shared_as_a_colored_image(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
        $register = Register::factory()->create([
            'vehicle_plate' => 'ABC1234',
            'vehicle_model' => 'Honda Civic',
            'origin_city' => 'Campinas',
            'destination_city' => 'Santos',
            'deadline_withdraw' => '2026-10-20',
            'deadline_delivery' => '2026-10-25',
            'status' => 'pending',
            'tow_yard' => 'Pátio central',
            'condition' => 'Ñ ANDA',
            'value' => 1234.56,
            'vehicle_plate_color' => 'blue',
            'destination_city_color' => 'green',
        ]);

        Livewire::test(ListRegisters::class)
            ->callTableBulkAction('copyAsImage', [$register])
            ->assertDispatched('registers-copy-as-image', fn (string $event, array $params): bool => $event === 'registers-copy-as-image'
                && $params['rows'][0]['plate'] === 'ABC1234'
                && $params['rows'][0]['withdraw'] === '20/10'
                && $params['rows'][0]['value'] === 'R$ 1.234,56'
                && $params['rows'][0]['condition'] === 'Ñ ANDA'
                && $params['rows'][0]['colors']['vehicle_plate_color'] === 'blue'
                && $params['rows'][0]['colors']['destination_city_color'] === 'green'
                && $params['rows'][0]['colors']['deadline_withdraw_color'] === null
                && $params['rows'][0]['colors']['deadline_delivery_color'] === null
            );
    }

    public function test_registers_can_be_searched_by_historical_cte_number(): void
    {
        $linkedRegister = Register::factory()->create(['vehicle_model' => 'Linked vehicle']);
        $unlinkedRegister = Register::factory()->create(['vehicle_model' => 'Unlinked vehicle']);

        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '900100',
        ]);
        CteDocument::factory()->create([
            'register_id' => $linkedRegister->id,
            'status' => CteDocumentStatusEnum::SUPERSEDED,
            'cte_number' => '800001',
        ]);
        CteDocument::factory()->create([
            'register_id' => $unlinkedRegister->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'cte_number' => '900200',
        ]);

        Livewire::test(ListRegisters::class)
            ->searchTable('800001')
            ->assertCanSeeTableRecords([$linkedRegister])
            ->assertCanNotSeeTableRecords([$unlinkedRegister]);
    }

    public function test_registers_can_be_searched_by_a_list_of_exact_vehicle_plates(): void
    {
        $plates = [
            'ABC1234', 'ABC1D23', 'DEF5678', 'DEF5G78', 'GHI9012', 'GHI9J12',
            'JKL3456', 'JKL3M56', 'MNO7890', 'MNO7P90', 'PQR1234', 'PQR1S34', 'TUV5678',
        ];
        $registers = collect($plates)->map(fn (string $plate): Register => Register::factory()->create([
            'vehicle_plate' => $plate,
        ]));
        $partial = Register::factory()->create(['vehicle_plate' => 'ZZABC1234']);
        $other = Register::factory()->create(['vehicle_plate' => 'WXY9876']);

        Livewire::test(ListRegisters::class)
            ->searchTable("abc-1234\nabc1d23, DEF-5678; def5g78 ghi9012\nghi9j12 JKL3456; JKL3M56 MNO7890, MNO7P90 PQR1234\nPQR1S34 TUV5678")
            ->assertCanSeeTableRecords($registers->all())
            ->assertCanNotSeeTableRecords([$partial, $other]);
    }

    public function test_register_plate_list_search_combines_with_table_filters(): void
    {
        $matching = Register::factory()->create(['vehicle_plate' => 'ABC1234', 'status' => 'pending']);
        $filteredOut = Register::factory()->create(['vehicle_plate' => 'ABC1D23', 'status' => 'paid']);

        Livewire::test(ListRegisters::class)
            ->set('columnFilters.status', 'pending')
            ->searchTable('ABC1234; ABC1D23')
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$filteredOut]);
    }

    public function test_duplicate_plate_tokens_still_use_plate_list_search(): void
    {
        $exact = Register::factory()->create(['vehicle_plate' => 'ABC1234']);
        $partial = Register::factory()->create(['vehicle_plate' => 'ZZABC1234']);

        Livewire::test(ListRegisters::class)
            ->searchTable('ABC1234, abc-1234')
            ->assertCanSeeTableRecords([$exact])
            ->assertCanNotSeeTableRecords([$partial]);
    }

    public function test_single_plate_search_keeps_existing_partial_search_behavior(): void
    {
        $exact = Register::factory()->create(['vehicle_plate' => 'ABC1234']);
        $partial = Register::factory()->create(['vehicle_plate' => 'ZZABC1234']);

        Livewire::test(ListRegisters::class)
            ->searchTable('ABC1234')
            ->assertCanSeeTableRecords([$exact])
            ->assertCanSeeTableRecords([$partial]);
    }

    public function test_register_search_keeps_existing_city_and_model_search_behavior(): void
    {
        $register = Register::factory()->create([
            'vehicle_model' => 'Honda Civic',
            'origin_city' => 'Campinas',
        ]);
        $other = Register::factory()->create([
            'vehicle_model' => 'Toyota Corolla',
            'origin_city' => 'Santos',
        ]);

        Livewire::test(ListRegisters::class)
            ->searchTable('Campinas')
            ->assertCanSeeTableRecords([$register])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('Honda')
            ->assertCanSeeTableRecords([$register])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_register_view_shows_the_latest_authorized_cte_and_delivery_date(): void
    {
        $register = Register::factory()->create([
            'delivery_confirmed_at' => Carbon::create(2026, 8, 12, 15, 30, 'UTC'),
        ]);

        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100001',
            'issued_at' => Carbon::create(2026, 8, 10, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 10, 13, 0, 'UTC'),
        ]);
        $latest = CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '100002',
            'issued_at' => Carbon::create(2026, 8, 11, 12, 0, 'UTC'),
            'authorized_at' => Carbon::create(2026, 8, 11, 13, 0, 'UTC'),
            'protocol' => 'protocol-secret',
            'access_key' => '35260812563112000130570010000026701338262343',
        ]);

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertFormFieldExists('vehicle_model')
            ->assertFormFieldIsDisabled('vehicle_model')
            ->assertFormFieldExists('vehicle_plate')
            ->assertFormFieldIsDisabled('vehicle_plate')
            ->assertFormFieldExists('origin_city')
            ->assertFormFieldIsDisabled('origin_city')
            ->assertFormFieldExists('status')
            ->assertFormFieldIsDisabled('status')
            ->assertFormFieldExists('consignor_letter_path')
            ->assertFormFieldExists('value')
            ->assertFormFieldIsDisabled('value')
            ->assertSee('Número do CT-e')
            ->assertSee('100002')
            ->assertSee('Emissão do CT-e')
            ->assertSee('11/08/2026 09:00')
            ->assertSee('Data da entrega')
            ->assertSee('12/08/2026 12:30')
            ->assertDontSee($latest->protocol)
            ->assertDontSee($latest->access_key);
    }

    public function test_register_view_shows_placeholders_when_cte_and_delivery_are_missing(): void
    {
        $register = Register::factory()->create();

        Livewire::test(ViewRegister::class, ['record' => $register->getRouteKey()])
            ->assertSee('Número do CT-e')
            ->assertSee('Emissão do CT-e')
            ->assertSee('Data da entrega')
            ->assertSee('Não informado');
    }

    public function test_register_list_and_view_show_unresolved_removal_imports(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Revisão pendente');

        $this->assertTrue($register->unresolvedRemovalImports()->whereKey($item->id)->exists());
    }

    public function test_register_integration_column_summarizes_removal_alerts(): void
    {
        $register = Register::factory()->create();
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['freight_changed', 'zero_fipe'],
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Frete alterado, FIPE zerada');
    }

    public function test_register_integration_column_shows_readded_alert(): void
    {
        $register = Register::factory()->create(['status' => 'pending']);
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['register_readded'],
        ]);

        Livewire::test(ListRegisters::class)
            ->assertSee('Registro readicionado');
    }

    public function test_acknowledging_from_the_integration_column_resolves_the_alert(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'alert',
            'register_id' => $register->id,
            'alerts' => ['zero_fipe'],
        ]);

        Livewire::test(ListRegisters::class)
            ->callTableAction('acknowledgeRemovalImport', $register);

        $this->assertSame('processed', $item->refresh()->status);
        $this->assertNotNull($item->resolved_at);
        $this->assertSame(auth()->id(), $item->resolved_by);

        Livewire::test(ListRegisters::class)
            ->assertDontSee('FIPE zerada');
    }

    public function test_blocked_removal_imports_do_not_offer_acknowledgement_from_the_integration_column(): void
    {
        $register = Register::factory()->create();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
            'failure_reason' => 'update_blocked_by_status',
        ]);

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $acknowledgeAction = $table->getColumn('unresolved_removal_imports_exists')->getAction()
            ->record($register);

        $this->assertFalse($acknowledgeAction->isVisible());

        Livewire::test(ListRegisters::class)
            ->assertSee('Atualização bloqueada pelo status');

        $this->assertSame('pending', $item->refresh()->status);
        $this->assertNull($item->resolved_at);
    }

    public function test_register_alert_uses_a_table_action_instead_of_a_nested_link(): void
    {
        $register = Register::factory()->create();
        IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);
        $register->load('unresolvedRemovalImports');

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $alertColumn = $table->getColumn('unresolved_removal_imports_exists')->record($register);

        $this->assertNull($alertColumn->getUrl());
        $this->assertTrue($table->hasAction('viewRemovalImport'));
        $this->assertTrue($table->getAction('viewRemovalImport')->record($register)->isVisible());
    }

    public function test_register_list_shows_the_consignor_letter_when_it_exists(): void
    {
        $register = Register::factory()->create([
            'consignor_letter_path' => 'registros/copart/123/CartaDoComitente ABC1D23.pdf',
        ]);

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $column = $table->getColumn('consignor_letter_path')->record($register);

        $this->assertSame('Comitente', $column->formatState($column->getState()));
        $this->assertNotNull($column->getUrl());
    }

    public function test_register_list_shows_the_authorized_cte_number(): void
    {
        $register = Register::factory()->create();
        CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_number' => '900100',
        ]);

        $table = Livewire::test(ListRegisters::class)->instance()->getTable();
        $column = $table->getColumn('latestAuthorizedCteDocument.cte_number')->record($register);

        $this->assertSame('900100', $column->getState());
    }

    public function test_the_cte_batch_action_lists_the_authorized_ctes_of_the_selection(): void
    {
        $register = Register::factory()->create();
        CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_number' => '900100',
        ]);

        $description = Livewire::test(ListRegisters::class)
            ->mountTableBulkAction('createCteEmissionBatch', [$register])
            ->instance()
            ->getMountedTableBulkAction()
            ->getModalDescription();

        $this->assertNotNull($description);
        $this->assertStringContainsString('CT-e 900100', $description);
        $this->assertStringContainsString('serão incluídos em um novo lote', $description);

        $fresh = Register::factory()->create();

        $this->assertNull(
            Livewire::test(ListRegisters::class)
                ->mountTableBulkAction('createCteEmissionBatch', [$fresh])
                ->instance()
                ->getMountedTableBulkAction()
                ->getModalDescription(),
        );
    }

    public function test_the_cte_batch_action_requires_confirmation_for_registers_with_an_authorized_cte(): void
    {
        $register = Register::factory()->create();
        $oldBatch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_emission_batch_id' => $oldBatch->id,
        ]);

        Livewire::test(ListRegisters::class)
            ->callTableBulkAction('createCteEmissionBatch', [$register], ['execution_mode' => 'dry_run'])
            ->assertHasTableBulkActionErrors(['confirm_reemission']);

        $this->assertSame(1, CteEmissionBatch::count());
    }

    public function test_the_cte_batch_action_creates_a_reemission_when_confirmed(): void
    {
        $register = Register::factory()->create();
        $oldDocument = CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_number' => '900100',
        ]);

        Livewire::test(ListRegisters::class)
            ->callTableBulkAction('createCteEmissionBatch', [$register], [
                'execution_mode' => 'dry_run',
                'confirm_reemission' => true,
                'reemission_reason' => 'Recusado pelo contratante',
            ])
            ->assertNotified('Lote de CT-e criado');

        $document = CteDocument::query()
            ->where('register_id', $register->id)
            ->whereKeyNot($oldDocument->id)
            ->first();

        $this->assertNotNull($document);
        $this->assertSame($oldDocument->id, $document->replaced_document_id);
        $this->assertSame('Recusado pelo contratante', $document->replacement_reason);
    }

    public function test_the_cte_batch_action_does_not_require_confirmation_for_fresh_registers(): void
    {
        $registers = Register::factory()->count(2)->create();

        Livewire::test(ListRegisters::class)
            ->callTableBulkAction('createCteEmissionBatch', $registers, ['execution_mode' => 'dry_run'])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Lote de CT-e criado');

        $batch = CteEmissionBatch::query()->sole();
        $this->assertSame(2, $batch->documents()->count());
        $this->assertNull($batch->documents()->first()->replaced_document_id);
    }
}
