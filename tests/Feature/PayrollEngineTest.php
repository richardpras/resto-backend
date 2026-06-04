<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollEngineTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_run_requires_locked_preparation_period(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Engine Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'eng-out',
        ]);

        $period = PayrollPreparationPeriod::query()->create([
            'outlet_id' => $outlet->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'status' => PayrollPreparationPeriod::STATUS_APPROVED,
        ]);

        $this->postJson('/api/v1/payroll-runs-v2', [
            'payrollPreparationPeriodId' => $period->id,
        ])->assertStatus(422);
    }
}
