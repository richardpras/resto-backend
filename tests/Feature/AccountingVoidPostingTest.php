<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Purchase\Domain\ProcurementPosting;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Purchase\Services\ProcurementPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class AccountingVoidPostingTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_void_supplier_payment_reverses_procurement_posting(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);
        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);
        $invoiceId = $this->createApprovedInvoice($master['poId'], $grnId, [
            ['inventoryItemId' => $master['ingredientId'], 'qty' => 5],
        ]);
        $paymentId = $this->createPostedSupplierPayment($master['supplierId'], (int) $outlet->id, 50000, [
            ['invoiceId' => $invoiceId, 'allocatedAmount' => 50000],
        ]);

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/void")
            ->assertOk();

        $this->assertDatabaseHas('procurement_postings', [
            'source_type' => ProcurementPosting::SOURCE_SUPPLIER_PAYMENT,
            'source_id' => $paymentId,
            'status' => ProcurementPosting::STATUS_REVERSED,
        ]);
    }
}
