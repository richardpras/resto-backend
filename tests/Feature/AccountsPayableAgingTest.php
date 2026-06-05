<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class AccountsPayableAgingTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_ap_aging_report_groups_by_supplier_and_bucket(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 10],
        ]);
        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 10],
        ]);

        DB::table('purchase_invoices')->where('id', $invoiceId)->update([
            'due_date' => now()->subDays(15)->toDateString(),
            'outstanding_amount' => 100000,
        ]);

        $response = $this->getJson('/api/v1/procurement/ap-aging?outletId='.$outlet->id)->assertOk();
        $response->assertJsonPath('data.suppliers.0.supplierId', (string) $master['supplierId']);
        $response->assertJsonPath('data.suppliers.0.days1to30', 100000);
        $response->assertJsonPath('data.totals.days1to30', 100000);
    }
}
