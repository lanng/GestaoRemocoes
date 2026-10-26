<?php

namespace Tests\Feature\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\Ciot;
use App\Models\CiotVehicle;
use App\Models\Company;
use App\Models\CteEmissionBatch;
use App\Services\Ciot\CreateCiotForBatch;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CreateCiotForBatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function formData(Company $payer, CiotVehicle $tractor): array
    {
        return [
            'status' => CiotStatusEnum::DRAFT->value,
            'line' => 'vehicle_removal',
            'operation_type' => 'lotation',
            'payer_id' => $payer->id,
            'delivery_payer_id' => $payer->id,
            'origin' => ['cidade' => 'Osvaldo Cruz', 'uf' => 'SP', 'cep' => '17700000', 'ibge' => '3534609'],
            'destination' => ['cidade' => 'Caçapava', 'uf' => 'SP', 'cep' => '12286140', 'ibge' => '3508504'],
            'distance_km' => '716',
            'freight_value' => '500.00',
            'cargo_weight_kg' => '2000',
            'vehicle_ids' => [$tractor->id],
            'travel_start_at' => now()->addDay()->format('Y-m-d'),
            'travel_end_at' => now()->addDays(2)->format('Y-m-d'),
        ];
    }

    public function test_it_creates_a_linked_ciot_with_the_form_snapshot(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);

        $ciot = app(CreateCiotForBatch::class)->handle($batch, $this->formData($payer, $tractor));

        $this->assertTrue(Str::isUuid((string) $ciot->public_id));
        $this->assertSame($batch->id, $ciot->cte_emission_batch_id);
        $this->assertSame(CiotStatusEnum::DRAFT, $ciot->status);
        $this->assertSame('14517191000925', $ciot->payer_cnpj);
        $this->assertSame('Copart Caçapava', $ciot->payer_name);
        $this->assertSame('14517191000925', $ciot->delivery_payer_cnpj);
        $this->assertSame(50000, $ciot->freight_value_cents);
        $this->assertSame('PUC8E55', $ciot->vehicles[0]['placa']);
        $this->assertNull($ciot->error_message);

        $activity = Activity::query()
            ->where('subject_type', Ciot::class)
            ->where('subject_id', $ciot->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame("CIOT gerado pelo lote de CT-e #{$batch->id}.", $activity->description);
    }

    public function test_it_rejects_a_batch_that_already_has_an_active_ciot(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::ISSUED,
        ]);

        try {
            app(CreateCiotForBatch::class)->handle($batch, $this->formData($payer, $tractor));
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertSame('Este lote já possui um CIOT ativo', $exception->getMessage());
        }

        $this->assertSame(1, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_it_accepts_a_batch_whose_only_ciot_was_canceled(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::APPROVED]);
        Ciot::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CiotStatusEnum::CANCELED,
            'cancel_reason' => 'Carga não ocorreu',
        ]);

        $ciot = app(CreateCiotForBatch::class)->handle($batch, $this->formData($payer, $tractor));

        $this->assertSame($batch->id, $ciot->cte_emission_batch_id);
        $this->assertSame(2, Ciot::query()->where('cte_emission_batch_id', $batch->id)->count());
    }

    public function test_it_rejects_a_stale_batch_after_the_batch_was_reopened_for_review(): void
    {
        $payer = Company::factory()->create(['cnpj' => '14517191000925', 'name' => 'Copart Caçapava']);
        $tractor = CiotVehicle::factory()->create(['plate' => 'PUC8E55', 'type' => 'automotor', 'axles' => 3]);
        $batch = CteEmissionBatch::factory()->create(['status' => CteEmissionBatchStatusEnum::DRAFT]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O CIOT não pode ser preparado enquanto o lote de CT-e estiver em revisão.');

        app(CreateCiotForBatch::class)->handle($batch, $this->formData($payer, $tractor));
    }
}
