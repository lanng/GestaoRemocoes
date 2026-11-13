<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Services\Dfe\AssertCteDocumentsEligibleForMdfe;
use DomainException;
use Illuminate\Support\Collection;

class ValidateMdfeDocumentSelection
{
    public function __construct(
        private readonly AssertCteDocumentsEligibleForMdfe $cteEligibility,
    ) {}

    /**
     * @param  Collection<int, CteDocument>  $documents
     */
    public function handle(Collection $documents, Ciot $ciot): CiotLineEnum
    {
        if ($documents->isEmpty()) {
            throw new DomainException('Selecione ao menos um CT-e autorizado para montar o MDF-e.');
        }

        if ($documents->contains(fn (mixed $document): bool => ! $document instanceof CteDocument || ! $document->exists)) {
            throw new DomainException('A seleção contém documento fiscal que não pertence ao cadastro interno de CT-es.');
        }

        if ($documents->pluck('id')->unique()->count() !== $documents->count()) {
            throw new DomainException('A seleção contém o mesmo CT-e mais de uma vez.');
        }

        if ($documents->contains(fn (CteDocument $document): bool => $document->status !== CteDocumentStatusEnum::AUTHORIZED
            || ! preg_match('/^\d{44}$/', (string) $document->access_key))) {
            throw new DomainException('Todos os CT-es selecionados precisam estar autorizados e possuir chave de acesso de 44 dígitos.');
        }

        $this->cteEligibility->handle($documents);

        $lines = $documents
            ->map(fn (CteDocument $document): CiotLineEnum => $this->documentLine($document))
            ->unique()
            ->values();

        if ($lines->count() !== 1) {
            throw new DomainException('O MDF-e não pode misturar CT-es de modalidades diferentes.');
        }

        $line = $lines->first();

        if (in_array($line, [CiotLineEnum::TankAlcohol, CiotLineEnum::GeneralCargo], true) && $documents->count() !== 1) {
            throw new DomainException($line === CiotLineEnum::GeneralCargo
                ? 'O MDF-e avulso de carga geral aceita exatamente um CT-e.'
                : 'O MDF-e de álcool aceita exatamente um CT-e.');
        }

        $ciotLine = $ciot->line instanceof CiotLineEnum
            ? $ciot->line
            : CiotLineEnum::tryFrom((string) $ciot->line);

        $ciotMatchesLine = $ciotLine === $line
            || ($line === CiotLineEnum::GeneralCargo && $ciotLine === CiotLineEnum::VehicleRemoval);

        if (! $ciotMatchesLine) {
            throw new DomainException('A linha do CIOT deve corresponder à modalidade dos CT-es selecionados.');
        }

        if ($ciot->isSyntheticHomologation()) {
            throw new DomainException('CIOT sintético local não pode ser usado para emitir MDF-e.');
        }

        if ($ciot->status === CiotStatusEnum::ISSUED) {
            return $line;
        }

        throw new DomainException('Selecione um CIOT emitido e ainda aberto para a modalidade escolhida.');
    }

    private function documentLine(CteDocument $document): CiotLineEnum
    {
        $line = CiotLineEnum::tryFrom((string) ($document->snapshot['line'] ?? CiotLineEnum::VehicleRemoval->value));

        if ($line === null) {
            throw new DomainException('O CT-e selecionado não possui uma modalidade de MDF-e reconhecida.');
        }

        return $line;
    }
}
