<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingHealthSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthTrendTest extends TestCase
{
    use AccountingRemediationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_trends_endpoint_returns_series(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Trend Outlet');

        AccountingHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'snapshot_date' => now()->subDays(2)->toDateString(),
            'posting_failures' => 2,
            'gift_card_variance' => 0,
            'inventory_variance' => 0,
            'payroll_variance' => 0,
            'procurement_variance' => 0,
            'severity' => 'warning',
        ]);

        $this->getJson('/api/v1/accounting/health/trends?outletId='.(int) $outlet->id.'&startDate='.now()->subDays(7)->toDateString().'&endDate='.now()->toDateString())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'postingFailures',
                    'giftCardVariance',
                    'inventoryVariance',
                    'severityTrend',
                ],
            ])
            ->assertJsonPath('data.postingFailures.0.count', 2);
    }
}
