<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Services\Dfe\DfeDocumentStore;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateGeneralCargoCteDocument
{
    public function __construct(
        private ExtractNfeReference $nfeReference,
        private DfeDocumentStore $documentStore,
        private ValidateGeneralCargoCteSnapshot $snapshotValidator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): CteDocument
    {
        $publicId = (string) Str::uuid();
        $mode = $this->originDocumentMode($data);
        $references = $mode === 'nfe' ? $this->nfeReferences($data) : [];
        $attachment = $mode === 'other' ? $this->cartaAttachment($data['carta_attachment'] ?? null, $publicId) : null;
        $snapshot = $this->snapshot($data, $references, $mode, $attachment);
        $this->snapshotValidator->handle($snapshot);
        $archivedFiles = [];

        foreach ($references as $reference) {
            $archivedFiles[] = filled($reference['xml'])
                ? $this->documentStore->storeNfeOriginal($reference['access_key'], $reference['xml'])
                : null;
        }

        if ($attachment !== null) {
            $this->documentStore->store($attachment['metadata']['path'], $attachment['contents']);
        }

        return DB::transaction(function () use ($publicId, $snapshot, $references, $archivedFiles): CteDocument {
            $document = CteDocument::query()->create([
                'public_id' => $publicId,
                'cte_emission_batch_id' => null,
                'register_id' => null,
                'status' => CteDocumentStatusEnum::DRAFT,
                'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
                'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
                'snapshot' => $snapshot,
                'idempotency_key' => (string) Str::uuid(),
                'execution_mode' => 'live',
                'emitter' => 'direct',
            ]);

            foreach ($references as $position => $reference) {
                $archive = $archivedFiles[$position];
                $document->nfeDocuments()->create([
                    'access_key' => $reference['access_key'],
                    'source_filename' => $reference['filename'],
                    'xml_path' => $archive['path'] ?? null,
                    'sha256' => $archive['sha256'] ?? null,
                    'extracted_data' => $reference['extracted_data'],
                ]);
            }

            return $document->fresh(['nfeDocuments']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{access_key: string, filename: string|null, xml: string|null, extracted_data: array<string, mixed>|null}>  $references
     * @param  array{metadata: array{filename: string, path: string, sha256: string, mime_type: string}, contents: string}|null  $attachment
     * @return array<string, mixed>
     */
    private function snapshot(array $data, array $references, string $mode, ?array $attachment): array
    {
        $origin = $this->city((array) ($data['origin'] ?? []));
        $destination = $this->city((array) ($data['destination'] ?? []));
        $parties = [
            'rem' => $this->companyParty($data['rem_company_id'] ?? null, 'remetente'),
            'dest' => $this->companyParty($data['dest_company_id'] ?? null, 'destinatário'),
            'exped' => null,
            'receb' => null,
            'taker' => null,
        ];
        $takerCode = (string) ($data['taker_code'] ?? '');

        if ($takerCode === '4') {
            $parties['taker'] = $this->party((array) ($data['taker'] ?? []));
        }

        $otherDocument = $mode === 'other' ? [
            'type' => 'carta_remocao',
            'tp_doc' => '99',
            'description' => 'CARTA DE REMOCAO',
            'number' => filled($data['carta_number'] ?? null) ? trim((string) $data['carta_number']) : null,
            'issued_on' => filled($data['carta_issued_on'] ?? null) ? trim((string) $data['carta_issued_on']) : null,
            'attachment' => $attachment['metadata'] ?? null,
        ] : null;

        $indIeTaker = trim((string) ($data['ind_ietoma'] ?? ''));
        $cargo = (array) ($data['cargo'] ?? []);
        $freight = (array) ($data['freight'] ?? []);
        $cfop = trim((string) ($data['cfop'] ?? ''));
        $measurements = array_values(array_map(function (array $measurement): array {
            $unit = trim((string) ($measurement['unit'] ?? ''));

            return [
                'unit' => $unit,
                'measure' => trim((string) ($measurement['measure'] ?? '')),
                'quantity' => $this->decimal(
                    (string) ($measurement['quantity'] ?? ''),
                    $unit === '01' ? 2 : 4,
                    'quantidade da carga',
                ),
            ];
        }, (array) ($cargo['measurements'] ?? [])));
        $grossWeight = collect($measurements)->first(fn (array $measurement): bool => $measurement['unit'] === '01'
            && mb_strtoupper($measurement['measure']) === 'PESO BRUTO');

        if ($cfop === '') {
            $cfop = $this->suggestCfop($origin['uf'], $destination['uf']);
        }

        $isTransportCompany = filter_var($data['taker_is_transport_company'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return [
            'schema_version' => 5,
            'line' => 'general_cargo',
            'origin_document_mode' => $mode,
            'other_document' => $otherDocument,
            'tax_review_required' => $origin['uf'] !== strtoupper((string) config('dfe.emit.uf')),
            'cfop' => $cfop,
            'nat_op' => trim((string) ($data['nat_op'] ?? 'PREST SERV TRANSPORTE')),
            'taker_code' => $takerCode,
            'ind_ietoma' => $indIeTaker,
            'taker_is_transport_company' => $isTransportCompany,
            'origin' => $origin,
            'destination' => $destination,
            ...$parties,
            'nfe_documents' => array_map(fn (array $reference): array => ['access_key' => $reference['access_key']], $references),
            'cargo' => [
                'value' => $this->decimal((string) ($cargo['value'] ?? ''), 2, 'valor da carga'),
                'predominant_product' => trim((string) ($cargo['predominant_product'] ?? '')),
                'other_category' => trim((string) ($cargo['other_category'] ?? '')),
                ...($grossWeight !== null ? ['weight_kg' => $grossWeight['quantity']] : []),
                'measurements' => $measurements,
            ],
            'freight' => [
                'total' => $this->decimal((string) ($freight['total'] ?? ''), 2, 'valor total do frete'),
                'receivable' => $this->decimal((string) ($freight['receivable'] ?? ''), 2, 'valor a receber'),
                'components' => array_values(array_map(fn (array $component): array => [
                    'name' => trim((string) ($component['name'] ?? '')),
                    'value' => $this->decimal((string) ($component['value'] ?? ''), 2, 'componente do frete'),
                ], (array) ($freight['components'] ?? []))),
            ],
            'value' => $this->decimal((string) ($freight['total'] ?? ''), 2, 'valor total do frete'),
            'cargo_value' => $this->decimal((string) ($cargo['value'] ?? ''), 2, 'valor da carga'),
            ...($grossWeight !== null ? ['cargo_weight_kg' => $grossWeight['quantity']] : []),
        ];
    }

    private function originDocumentMode(array $data): string
    {
        $mode = trim((string) ($data['origin_document_mode'] ?? 'nfe'));

        if (! in_array($mode, ['nfe', 'other'], true)) {
            throw new DomainException('Escolha NF-e ou Carta de remoção como documento originário.');
        }

        $hasNfeReferences = filled($data['nfe_keys'] ?? null) || (array) ($data['nfe_xmls'] ?? []) !== [];
        $hasCartaData = filled($data['carta_number'] ?? null)
            || filled($data['carta_issued_on'] ?? null)
            || ($data['carta_attachment'] ?? null) !== null;

        if ($mode === 'other' && $hasNfeReferences) {
            throw new DomainException('Carta de remoção não pode ser combinada com referências de NF-e.');
        }

        if ($mode === 'nfe' && $hasCartaData) {
            throw new DomainException('Referências de NF-e não podem ser combinadas com os dados da Carta de remoção.');
        }

        return $mode;
    }

    /** @return array{metadata: array{filename: string, path: string, sha256: string, mime_type: string}, contents: string}|null */
    private function cartaAttachment(mixed $attachment, string $publicId): ?array
    {
        if ($attachment === null) {
            return null;
        }

        $filename = basename(str_replace('\\', '/', trim((string) ($attachment['filename'] ?? ''))));
        $contents = $attachment['contents'] ?? null;

        if ($filename === '' || mb_strlen($filename) > 255 || ! is_string($contents) || $contents === '') {
            throw new DomainException('O anexo da carta não possui nome ou conteúdo válido.');
        }

        if (strlen($contents) > 10 * 1024 * 1024) {
            throw new DomainException('O anexo da carta deve ter no máximo 10 MB.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        $extensionsByMime = [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
        ];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, $extensionsByMime[$mimeType] ?? [], true)) {
            throw new DomainException('O anexo da carta deve ser um arquivo PDF, JPEG ou PNG válido.');
        }

        $sha256 = hash('sha256', $contents);

        return [
            'metadata' => [
                'filename' => $filename,
                'path' => "cte-drafts/{$publicId}/carta-remocao/{$sha256}.{$extension}",
                'sha256' => $sha256,
                'mime_type' => $mimeType,
            ],
            'contents' => $contents,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{access_key: string, filename: string|null, xml: string|null, extracted_data: array<string, mixed>|null}>
     */
    private function nfeReferences(array $data): array
    {
        $references = [];
        $keys = preg_split('/[\r\n,;]+/', (string) ($data['nfe_keys'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $xmlFiles = (array) ($data['nfe_xmls'] ?? []);
        $totalXmlBytes = 0;

        foreach ($xmlFiles as $file) {
            if (! is_array($file) || ! is_string($file['filename'] ?? null) || ! is_string($file['xml'] ?? null)) {
                throw new DomainException('Reenvie os XMLs de NF-e antes de salvar o rascunho.');
            }

            $totalXmlBytes += strlen($file['xml']);
        }

        $maximumTotalSizeBytes = (int) config('dfe.nfe_xml_max_total_size_bytes', 10 * 1024 * 1024);

        if ($totalXmlBytes > $maximumTotalSizeBytes) {
            $maximumTotalSizeMegabytes = number_format($maximumTotalSizeBytes / 1024 / 1024, 1, ',', '.');
            throw new DomainException("Os XMLs de NF-e devem totalizar no máximo {$maximumTotalSizeMegabytes} MB.");
        }

        foreach ($keys as $key) {
            $this->appendReference($references, [
                'access_key' => $this->nfeReference->fromKey(trim($key)),
                'filename' => null,
                'xml' => null,
                'extracted_data' => null,
            ]);
        }

        foreach ($xmlFiles as $file) {
            $filename = basename(str_replace('\\', '/', trim($file['filename'])));

            if ($filename === '' || mb_strlen($filename) > 255) {
                throw new DomainException('O nome do XML da NF-e está vazio ou excede 255 caracteres.');
            }

            $extractedData = $this->nfeReference->extractFromXml($file['xml'], $filename);
            $this->appendReference($references, [
                'access_key' => $extractedData['access_key'],
                'filename' => $filename,
                'xml' => $file['xml'],
                'extracted_data' => $extractedData,
            ]);
        }

        $maxReferences = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if ($references === [] || count($references) > $maxReferences) {
            throw new DomainException("Informe de uma a {$maxReferences} referências de NF-e.");
        }

        return $references;
    }

    /**
     * @param  list<array{access_key: string, filename: string|null, xml: string|null, extracted_data: array<string, mixed>|null}>  $references
     * @param  array{access_key: string, filename: string|null, xml: string|null, extracted_data: array<string, mixed>|null}  $reference
     */
    private function appendReference(array &$references, array $reference): void
    {
        if (collect($references)->contains(fn (array $existing): bool => $existing['access_key'] === $reference['access_key'])) {
            throw new DomainException('A seleção contém uma chave de NF-e duplicada.');
        }

        $references[] = $reference;
    }

    /** @return array<string, string|int> */
    private function companyParty(mixed $companyId, string $label): array
    {
        $company = is_numeric($companyId)
            ? Company::query()->where('is_active', true)->find((int) $companyId)
            : null;

        if ($company === null) {
            throw new DomainException("Selecione uma empresa ativa cadastrada como {$label}.");
        }

        try {
            return [
                ...$this->party([
                    'cnpj' => $company->cnpj,
                    'name' => $company->name,
                    'ie' => $company->ie,
                    'street' => $company->street,
                    'number' => $company->number,
                    'complement' => $company->complement,
                    'district' => $company->district,
                    'city' => $company->city,
                    'state' => $company->state,
                    'zipcode' => $company->zipcode,
                    'ibge' => $company->ibge_code,
                ]),
                'company_id' => $company->id,
            ];
        } catch (DomainException $exception) {
            throw new DomainException("O cadastro fiscal da empresa selecionada como {$label} está incompleto: {$exception->getMessage()}", previous: $exception);
        }
    }

    /** @param array<string, mixed> $city @return array{ibge: string, name: string, uf: string} */
    private function city(array $city): array
    {
        $ibge = preg_replace('/\D/', '', (string) ($city['ibge'] ?? '')) ?: '';
        $registeredCity = City::query()->where('ibge_code', $ibge)->first(['ibge_code', 'name', 'state']);

        if ($registeredCity === null) {
            throw new DomainException("O município IBGE {$ibge} não está cadastrado.");
        }

        return ['ibge' => $registeredCity->ibge_code, 'name' => $registeredCity->name, 'uf' => $registeredCity->state];
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function party(array $input): array
    {
        $city = $this->city([
            'ibge' => $input['ibge'] ?? '',
            'name' => $input['city'] ?? '',
            'uf' => $input['uf'] ?? $input['state'] ?? '',
        ]);

        return [
            'cnpj' => preg_replace('/\D/', '', (string) ($input['cnpj'] ?? '')) ?: '',
            'cpf' => preg_replace('/\D/', '', (string) ($input['cpf'] ?? '')) ?: '',
            'ie' => trim((string) ($input['ie'] ?? '')),
            'xNome' => trim((string) ($input['name'] ?? '')),
            'xLgr' => trim((string) ($input['street'] ?? '')),
            'nro' => trim((string) ($input['number'] ?? '')),
            'xCpl' => trim((string) ($input['complement'] ?? '')),
            'xBairro' => trim((string) ($input['district'] ?? '')),
            'cMun' => $city['ibge'],
            'xMun' => $city['name'],
            'CEP' => preg_replace('/\D/', '', (string) ($input['zipcode'] ?? '')) ?: '',
            'UF' => $city['uf'],
        ];
    }

    private function suggestCfop(string $originUf, string $destinationUf): string
    {
        if ($originUf === 'SP') {
            return $destinationUf === 'SP' ? '5353' : '';
        }

        return $originUf === $destinationUf ? '5932' : '6932';
    }

    private function decimal(string $value, int $scale, string $label): string
    {
        try {
            return ExactDecimal::format(ExactDecimal::parse($value, $scale, 'CT-e avulso', $label), $scale);
        } catch (DomainException $exception) {
            throw new DomainException("O campo {$label} deve conter um número válido com até {$scale} casas decimais.", previous: $exception);
        }
    }
}
