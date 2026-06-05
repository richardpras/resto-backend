<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Pph21Config;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class Pph21ConfigurationTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->actingAsHrmApiAdministrator();
    }

    public function test_list_pph21_configs(): void
    {
        Pph21Config::query()->create([
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/pph21-configs')
            ->assertOk()
            ->assertJsonPath('data.0.effectiveDate', '2026-01-01');
    }

    public function test_create_pph21_config_with_default_brackets(): void
    {
        $this->postJson('/api/v1/pph21-configs', [
            'effectiveDate' => '2026-06-01',
            'ptkpTk0' => 54000000,
            'isActive' => true,
        ])->assertCreated()
            ->assertJsonPath('data.effectiveDate', '2026-06-01')
            ->assertJsonPath('data.ptkpTk0', 54000000);

        $config = Pph21Config::query()->where('effective_date', '2026-06-01')->first();
        $this->assertNotNull($config);
        $this->assertGreaterThanOrEqual(5, $config->brackets()->count());
    }

    public function test_update_pph21_config(): void
    {
        $config = Pph21Config::query()->create([
            'effective_date' => '2026-01-01',
            'is_active' => true,
        ]);
        $config->brackets()->create(['income_from' => 0, 'income_to' => 60000000, 'tax_rate' => 5]);

        $this->patchJson('/api/v1/pph21-configs/'.$config->id, [
            'ptkpTk0' => 55000000,
            'isActive' => false,
        ])->assertOk()
            ->assertJsonPath('data.ptkpTk0', 55000000)
            ->assertJsonPath('data.isActive', false);
    }
}
