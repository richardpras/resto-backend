<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class PayrollPreparationPeriodTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_and_list_periods(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        $this->postJson('/api/v1/payroll-preparation-periods', [
            'outletId' => $outlet->id,
            'periodStart' => '2026-09-01',
            'periodEnd' => '2026-09-30',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $this->getJson('/api/v1/payroll-preparation-periods?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_period_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        $payload = [
            'outletId' => $outlet->id,
            'periodStart' => '2026-09-01',
            'periodEnd' => '2026-09-15',
        ];

        $this->postJson('/api/v1/payroll-preparation-periods', $payload)->assertCreated();
        $this->postJson('/api/v1/payroll-preparation-periods', $payload)->assertStatus(422);
    }

    private function seedOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Prep Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pay-prep',
        ]);
    }
}
