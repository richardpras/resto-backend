<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\BpjsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class BPJSConfigurationTest extends TestCase
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

    public function test_list_bpjs_configs(): void
    {
        BpjsConfig::query()->create([
            'effective_date' => '2026-01-01',
            'kesehatan_employee_rate' => 1,
            'kesehatan_company_rate' => 4,
            'status' => BpjsConfig::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/bpjs-configs')
            ->assertOk()
            ->assertJsonPath('data.0.effectiveDate', '2026-01-01');
    }

    public function test_create_bpjs_config(): void
    {
        $this->postJson('/api/v1/bpjs-configs', [
            'effectiveDate' => '2026-06-01',
            'kesehatanEmployeeRate' => 1,
            'kesehatanCompanyRate' => 4,
            'jhtEmployeeRate' => 2,
            'jhtCompanyRate' => 3.7,
            'jpEmployeeRate' => 1,
            'jpCompanyRate' => 2,
            'jkkCompanyRate' => 0.24,
            'jkmCompanyRate' => 0.3,
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('data.effectiveDate', '2026-06-01')
            ->assertJsonPath('data.kesehatanEmployeeRate', 1);

        $this->assertDatabaseHas('bpjs_configs', [
            'effective_date' => '2026-06-01',
            'status' => 'active',
        ]);
    }
}
