<?php

namespace App\Services\Cte;

use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use DomainException;
use Illuminate\Validation\ValidationException;

class CheckCteBatchParties
{
    public function __construct(private ResolveCteSnapshot $resolver) {}

    /** @return array<int, array<string, mixed>> */
    public function report(CteEmissionBatch $batch): array
    {
        $checks = [];
        foreach ($batch->documents()->where('status', 'draft')->with('register')->get() as $document) {
            $checks[$document->id] = $this->inspect($document);
        }

        return $checks;
    }

    public function isReady(CteEmissionBatch $batch): bool
    {
        $checks = $this->report($batch);

        return $checks !== [] && collect($checks)->every(fn (array $check): bool => $check['ready']);
    }

    public function summary(CteEmissionBatch $batch): string
    {
        $checks = $this->report($batch);
        $ready = collect($checks)->where('ready', true)->count();
        $total = count($checks);

        return "{$ready} de {$total} registros conferidos."
            .($ready === $total && $total > 0 ? ' O lote pode ser aprovado.' : ' Resolva as pendências na lista abaixo antes de aprovar.');
    }

    public function prepare(CteEmissionBatch $batch): void
    {
        $checks = $this->report($batch);
        $errors = [];
        foreach ($checks as $check) {
            if (! $check['ready']) {
                $errors[] = 'Placa '.$check['plate'].': '.implode(' ', $check['errors']);
            }
        }

        if ($checks === [] || $errors !== []) {
            throw ValidationException::withMessages(['batch' => $errors === []
                ? 'O lote não possui documentos para emissão.'
                : implode("\n", $errors)]);
        }

        foreach ($batch->documents()->where('status', 'draft')->get() as $document) {
            $document->forceFill(['snapshot' => $checks[$document->id]['snapshot']])->save();
        }
    }

    /** @return array<string, mixed> */
    private function inspect(CteDocument $document): array
    {
        $source = (array) $document->snapshot;
        $check = [
            'plate' => (string) ($source['vehicle_plate'] ?? $document->register?->vehicle_plate),
            'sender' => ['source' => (string) ($source['insurance'] ?? ''), 'error' => null],
            'destination' => ['source' => (string) ($source['destination_city'] ?? ''), 'error' => null],
            'errors' => [],
            'ready' => false,
            'snapshot' => null,
        ];

        foreach (['sender', 'destination'] as $role) {
            try {
                $resolved = $role === 'sender'
                    ? $this->resolver->resolveSender($check[$role]['source'])
                    : $this->resolver->resolveDestination($check[$role]['source']);
                $check[$role] = array_replace($check[$role], $resolved);
            } catch (DomainException $exception) {
                $check[$role]['error'] = $exception->getMessage();
                $check['errors'][] = $exception->getMessage();
            }
        }

        try {
            $check['origin'] = $this->resolver->resolveCity((string) ($source['origin_city'] ?? ''), 'origem');
        } catch (DomainException $exception) {
            $check['errors'][] = $exception->getMessage();
        }

        if ($check['errors'] === []) {
            $source['schema_version'] = 1;
            $resolved = $this->resolver->handle(new CteDocument(['snapshot' => $source]));
            $check['snapshot'] = array_replace($source, $resolved);
            $check['ready'] = true;
        }

        return $check;
    }
}
