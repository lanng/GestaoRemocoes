<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Filament\Actions\AddRegistersToCteBatchAction;
use App\Filament\Actions\GenerateCiotForBatchAction;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Models\Ciot;
use App\Models\MdfeDocument;
use App\Models\MdfeDriver;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Ciot\PrepareMdfeDocument;
use App\Services\Ciot\QueueDirectMdfeEmission;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\CheckCteBatchParties;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use App\Services\Cte\ReconcileMdfeDocument;
use App\Services\Dfe\CloseTransportOperation;
use App\Services\Dfe\CloseTransportOperationResult;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDamdfePdf;
use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    #[On('fiscal-parties-resolved')]
    public function refreshBatch(): void
    {
        $this->authorizeAccess();
        $this->getRecord()->refresh();
    }

    /** @return array<int, string> */
    protected function directMdfeCiotOptions(): array
    {
        return $this->record->ciots()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->latest('id')
            ->get()
            ->reject(fn (Ciot $ciot): bool => $ciot->isSyntheticHomologation())
            ->mapWithKeys(fn (Ciot $ciot): array => [
                $ciot->id => sprintf(
                    '#%d · %s · %s',
                    $ciot->id,
                    $ciot->line->label(),
                    $ciot->status->label(),
                ),
            ])
            ->all();
    }

    private function directMdfeBlockReason(): ?string
    {
        if ($this->record->documents()
            ->where('status', '!=', CteDocumentStatusEnum::AUTHORIZED->value)
            ->exists()
            || $this->record->documents()->whereNull('access_key')->exists()) {
            return 'Há CT-e não autorizado ou sem chave no lote. Consulte o motivo na lista e use “Preparar MDF-e” para selecionar apenas os CT-es autorizados.';
        }

        $eligibleDocumentsCount = $this->record->documents()->eligibleForNewMdfe()->count();
        $documentsCount = $this->record->documents()->count();

        if ($eligibleDocumentsCount !== $documentsCount) {
            return 'Há CT-e fora do fluxo fiscal controlado, sem elegibilidade para emissão direta de MDF-e.';
        }

        return null;
    }

    private function defaultDirectMdfeDriverId(mixed $ciotId): ?int
    {
        if ($this->selectedDirectMdfeCiot($ciotId)?->line !== CiotLineEnum::TankAlcohol) {
            return null;
        }

        $drivers = MdfeDriver::query()
            ->where('line', CiotLineEnum::TankAlcohol->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id']);

        return $drivers->count() === 1 ? (int) $drivers->first()->id : null;
    }

    protected function selectedDirectMdfeCiot(mixed $id): ?Ciot
    {
        $ciot = blank($id) ? null : $this->record->ciots()
            ->whereKey($id)
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->first();

        return $ciot?->isSyntheticHomologation() ? null : $ciot;
    }

    protected function mdfeAvailableForClosure(): ?MdfeDocument
    {
        return $this->record->mdfeDocuments()
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where('emitter', 'direct')
            ->whereNotNull('access_key')
            ->whereNotNull('protocol')
            ->latest('id')
            ->get()
            ->first(fn (MdfeDocument $document): bool => $document->isClosureAvailable());
    }

    protected function mdfePendingCiotClosure(): ?MdfeDocument
    {
        return $this->record->mdfeDocuments()
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where('emitter', 'direct')
            ->where('fiscal_status_code', '132')
            ->whereHas('ciot', fn ($query) => $query->where('status', CiotStatusEnum::ISSUED->value))
            ->with('ciot')
            ->latest('id')
            ->get()
            ->first(fn (MdfeDocument $document): bool => ! $document->ciot?->isSyntheticHomologation());
    }

    private function hasNonCancelledMdfeForBatch(): bool
    {
        return MdfeDocument::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('cte_emission_batch_id', $this->record->id)
                    ->orWhereHas('cteDocuments', fn (Builder $query): Builder => $query
                        ->where('cte_emission_batch_id', $this->record->id));
            })
            ->whereNotIn('status', [
                CteDocumentStatusEnum::REJECTED->value,
                CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                CteDocumentStatusEnum::CANCELLED->value,
            ])
            ->where(function (Builder $query): void {
                $query->whereNull('fiscal_status')
                    ->orWhere('fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('fiscal_status_code')
                    ->orWhere('fiscal_status_code', '!=', '101');
            })
            ->exists();
    }

    private function runTransportClosure(MdfeDocument $mdfe): void
    {
        try {
            $result = app(CloseTransportOperation::class)->handle($mdfe);
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Não foi possível encerrar a operação de transporte.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(match ($result->status) {
                CloseTransportOperationResult::COMPLETED => match (true) {
                    $result->mdfe->ciot?->isSyntheticHomologation() => 'MDF-e encerrado; CIOT sintético mantido para testes.',
                    $result->mdfe->ciot_id === null => 'MDF-e encerrado pela SEFAZ.',
                    default => 'MDF-e e CIOT encerrados.',
                },
                CloseTransportOperationResult::MDFE_PENDING => 'Aguardando confirmação do encerramento pela SEFAZ.',
                default => 'MDF-e encerrado; encerramento do CIOT pendente na ANTT.',
            })
            ->body($result->message)
            ->color($result->status === CloseTransportOperationResult::COMPLETED ? 'success' : 'warning')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            AddRegistersToCteBatchAction::make($this->getRecord()),
            GenerateCiotForBatchAction::make($this->getRecord()),
            Actions\Action::make('prepareMdfe')
                ->label('Preparar MDF-e')
                ->icon('heroicon-m-document-plus')
                ->color('primary')
                ->url(fn (): string => MdfeDocumentResource::getUrl('create', ['batch' => $this->record->id]))
                ->visible(fn (): bool => app(PrepareMdfeDocument::class)
                    ->hasEligibleCteDocumentsForBatch((int) $this->record->id)),
            Actions\Action::make('downloadDamdfe')
                ->label('Baixar DAMDFE')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
                    ->whereNotNull('access_key')
                    ->whereNotNull('xml_path')
                    ->exists())
                ->action(function (): mixed {
                    $mdfe = $this->record->mdfeDocuments()
                        ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
                        ->whereNotNull('access_key')
                        ->whereNotNull('xml_path')
                        ->latest('id')
                        ->firstOrFail();
                    $pdfPath = $mdfe->pdf_path;

                    if (blank($pdfPath) || ! app(DfeDocumentStore::class)->exists($pdfPath)) {
                        $proc = app(DfeDocumentStore::class)->read((string) $mdfe->xml_path);
                        $pdfPath = app(GenerateDamdfePdf::class)->generateForProc(
                            (string) $mdfe->access_key,
                            $proc,
                        );
                        $mdfe->forceFill(['pdf_path' => $pdfPath])->save();
                    }

                    $filename = strtolower((string) $mdfe->access_key).'-damdfe.pdf';

                    return Storage::disk('dfe')->download((string) $pdfPath, $filename);
                }),
            Actions\Action::make('closeMdfe')
                ->label(fn (): string => $this->mdfeAvailableForClosure()?->events
                    ->where('event_type', '110112')
                    ->whereIn('status', ['pending', 'registered', 'reconciliation_required', 'duplicate_reconciliation'])
                    ->isNotEmpty() ? 'Reconciliar encerramento' : 'Encerrar operação de transporte')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $this->mdfeAvailableForClosure() !== null)
                ->requiresConfirmation()
                ->modalHeading('Encerrar operação de transporte')
                ->modalDescription('A cidade de encerramento será o destino final da viagem. A ANTT só será chamada depois que a SEFAZ confirmar o encerramento do MDF-e.')
                ->action(function (): void {
                    $mdfe = $this->mdfeAvailableForClosure();

                    if ($mdfe === null) {
                        Notification::make()->title('Não há MDF-e disponível para encerramento ou reenvio seguro.')->warning()->send();

                        return;
                    }

                    $this->runTransportClosure($mdfe);
                }),
            Actions\Action::make('resumeCiotClosure')
                ->label('Retomar encerramento do CIOT')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->mdfePendingCiotClosure() !== null)
                ->requiresConfirmation()
                ->modalHeading('Retomar somente o encerramento do CIOT')
                ->modalDescription('A SEFAZ já confirmou o encerramento do MDF-e. Esta ação tenta somente o encerramento do CIOT na ANTT.')
                ->action(function (): void {
                    $mdfe = $this->mdfePendingCiotClosure();

                    if ($mdfe === null) {
                        Notification::make()->title('Não há CIOT aberto para retomar.')->warning()->send();

                        return;
                    }

                    $this->runTransportClosure($mdfe);
                }),
            Actions\Action::make('reemitCiot')
                ->label('Reemitir CIOT')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->record->ciots()->where('status', CiotStatusEnum::FAILED->value)->exists())
                ->requiresConfirmation()
                ->modalHeading('Reemitir CIOT da viagem')
                ->modalDescription('Gera um novo IdOperacaoTransporte e reenvia a declaração à ANTT com os dados atuais do CIOT (edite-os pelo botão Editar do CIOT, se necessário).')
                ->action(function (): void {
                    /** @var Ciot|null $ciot */
                    $ciot = $this->record->ciots()
                        ->where('status', CiotStatusEnum::FAILED->value)
                        ->latest('id')
                        ->first();

                    if ($ciot === null) {
                        Notification::make()
                            ->title('Nenhum CIOT com falha neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $result = app(EmitCiotDeclaration::class)->emit($ciot);

                    CiotEmissionNotice::reemission()->send($result);

                    $this->refreshBatch();
                }),
            Actions\Action::make('emitMdfeDirect')
                ->label('Emitir MDF-e')
                ->icon('heroicon-m-paper-airplane')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->refresh()->status?->value, ['completed', 'completed_with_errors'], true)
                    && $this->directMdfeCiotOptions() !== []
                    && $this->record->documents()->exists()
                    && ! $this->hasNonCancelledMdfeForBatch())
                ->disabled(fn (): bool => $this->directMdfeBlockReason() !== null)
                ->tooltip(fn (): ?string => $this->directMdfeBlockReason())
                ->form([
                    Select::make('ciot_id')
                        ->label('CIOT emitido para esta operação')
                        ->options(fn (): array => $this->directMdfeCiotOptions())
                        ->default(fn (): int|string|null => array_key_first($this->directMdfeCiotOptions()))
                        ->placeholder('Selecione o CIOT')
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $set('driver_id', $this->defaultDirectMdfeDriverId($state));
                        })
                        ->required(),
                    Repeater::make('endorsements')
                        ->label('Averbações desta operação')
                        ->defaultItems(1)
                        ->helperText(filled(config('dfe.mdfe.default_endorsement'))
                            ? 'A averbação padrão está preenchida e pode ser alterada para esta operação.'
                            : 'Informe ao menos uma averbação para continuar.')
                        ->schema([
                            TextInput::make('number')
                                ->label('Número da averbação')
                                ->default(trim((string) config('dfe.mdfe.default_endorsement', '')))
                                ->maxLength(40)
                                ->required(),
                        ])
                        ->minItems(1)
                        ->defaultItems(1),
                    Select::make('driver_id')
                        ->label('Motorista')
                        ->options(fn (): array => MdfeDriver::query()
                            ->where('line', CiotLineEnum::TankAlcohol->value)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn (Get $get): ?int => $this->defaultDirectMdfeDriverId($get('ciot_id')))
                        ->searchable()
                        ->required(fn (\Filament\Forms\Get $get): bool => $this->selectedDirectMdfeCiot($get('ciot_id'))?->line === CiotLineEnum::TankAlcohol)
                        ->visible(fn (\Filament\Forms\Get $get): bool => $this->selectedDirectMdfeCiot($get('ciot_id'))?->line === CiotLineEnum::TankAlcohol),
                    Toggle::make('homologation_fixture')
                        ->label('Usar dados sintéticos de seguro para homologação')
                        ->helperText('Disponível apenas no ambiente de homologação; não salva esses dados na configuração.')
                        ->visible(fn (): bool => (string) config('dfe.tp_amb') === '2'),
                    TextInput::make('fixture_insurer_name')
                        ->label('Nome da seguradora de teste')
                        ->maxLength(30)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                    TextInput::make('fixture_insurer_cnpj')
                        ->label('CNPJ de teste')
                        ->maxLength(14)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                    TextInput::make('fixture_policy_number')
                        ->label('Apólice de teste')
                        ->maxLength(20)
                        ->required(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture'))
                        ->visible(fn (\Filament\Forms\Get $get): bool => (bool) $get('homologation_fixture')),
                ])
                ->requiresConfirmation()
                ->modalHeading('Emitir MDF-e direto na SEFAZ')
                ->modalDescription('O XML será assinado, validado e transmitido ao ambiente '.((string) config('dfe.tp_amb') === '1' ? 'de produção' : 'de homologação').'.')
                ->action(function (array $data): void {
                    $ciot = $this->selectedDirectMdfeCiot($data['ciot_id'] ?? null);

                    if ($ciot === null) {
                        Notification::make()->title('CIOT emitido não encontrado para esta viagem.')->danger()->send();

                        return;
                    }

                    $fixture = ($data['homologation_fixture'] ?? false) ? [
                        'insurer_name' => (string) $data['fixture_insurer_name'],
                        'insurer_cnpj' => (string) $data['fixture_insurer_cnpj'],
                        'policy_number' => (string) $data['fixture_policy_number'],
                    ] : null;

                    try {
                        $document = app(QueueDirectMdfeEmission::class)->handleForDocuments(
                            documents: $this->record->documents()->get(),
                            ciot: $ciot,
                            endorsements: collect($data['endorsements'] ?? [])->pluck('number')->all(),
                            routeUfs: [],
                            batch: $this->record,
                            driver: isset($data['driver_id']) ? MdfeDriver::query()->findOrFail($data['driver_id']) : null,
                            homologationFixture: $fixture,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()->title('MDF-e não foi enfileirado')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('MDF-e enviado à fila de emissão direta.')->success()->send();
                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('reconcileMdfe')
                ->label('Conciliar MDF-e')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->where('emitter', 'direct')
                    ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                    ->exists())
                ->form([
                    TextInput::make('access_key')
                        ->label('Chave de acesso do MDF-e (44 dígitos)')
                        ->mask(str_repeat('9', 44))
                        ->maxLength(44)
                        ->required(),
                    TextInput::make('protocol')
                        ->label('Protocolo de autorização (opcional)')
                        ->maxLength(60),
                ])
                ->modalHeading('Conciliar MDF-e da viagem')
                ->modalDescription('Informe a chave de acesso do MDF-e autorizado para registrar o resultado. O número e a série são derivados da chave.')
                ->action(function (array $data): void {
                    $mdfe = $this->record->mdfeDocuments()
                        ->where('emitter', 'direct')
                        ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                        ->latest('id')
                        ->first();

                    if ($mdfe === null) {
                        Notification::make()
                            ->title('Nenhum MDF-e aguardando conciliação neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        app(ReconcileMdfeDocument::class)->handle(
                            $mdfe,
                            (string) $data['access_key'],
                            filled($data['protocol'] ?? null) ? (string) $data['protocol'] : null,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Falha ao conciliar o MDF-e')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e conciliado e marcado como autorizado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('delete')
                ->label('Excluir lote')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => app(DeleteDraftCteEmissionBatch::class)->canDelete($this->record))
                ->requiresConfirmation()
                ->modalHeading('Excluir lote')
                ->modalDescription('CT-es rascunho sem evidência de comunicação serão removidos. Os demais permanecerão como documentos avulsos; os XMLs originais das NF-es serão preservados.')
                ->action(function (): void {
                    app(DeleteDraftCteEmissionBatch::class)->handle($this->record);
                    $this->redirect(CteEmissionBatchResource::getUrl('index'));
                })
                ->successNotificationTitle('Lote excluído.'),
            Actions\Action::make('approve')
                ->label('Aprovar lote')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->disabled(fn (): bool => ! app(CheckCteBatchParties::class)->isReady($this->record))
                ->tooltip('Todas as associações e os dados fiscais precisam estar conferidos antes da aprovação.')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ApproveCteEmissionBatch::class)->handle($this->record, auth()->user());
                })
                ->successNotificationTitle('Lote aprovado e encaminhado para emissão.'),
        ];
    }
}
