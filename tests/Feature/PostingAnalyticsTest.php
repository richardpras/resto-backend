<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PostingAnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_posting_analytics_returns_counts_and_rate(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);
        $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);

        $this->getJson('/api/v1/procurement/analytics/posting?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.postedGrnCount', 1)
            ->assertJsonPath('data.postedInvoiceCount', 1)
            ->assertJsonStructure([
                'data' => [
                    'postedGrnCount',
                    'postedInvoiceCount',
                    'postedPaymentCount',
                    'unpostedGrnCount',
                    'unpostedInvoiceCount',
                    'unpostedPaymentCount',
                    'postingRate',
                ],
            ]);
    }
}
