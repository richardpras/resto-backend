<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementAnalyticsSummaryTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_analytics_summary_returns_kpi_fields(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);

        $this->getJson('/api/v1/procurement/analytics/summary?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'totalSpend',
                    'totalPurchaseOrders',
                    'totalReceipts',
                    'totalInvoices',
                    'totalPayments',
                    'outstandingPayables',
                    'overduePayables',
                    'averagePoCycleDays',
                    'averageInvoiceCycleDays',
                    'matchRate',
                    'postingRate',
                ],
            ]);
    }
}
