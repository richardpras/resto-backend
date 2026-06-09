<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PostingStatusIndicatorTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seedAccountingAccounts();
    }

    public function test_grn_detail_includes_posted_posting_status_with_journal_link(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $grnId = $this->createPostedGoodsReceipt($master['poId'], (int) $outlet->id, [
            ['inventoryItemId' => $master['ingredientId'], 'receivedQty' => 5],
        ]);

        $this->getJson("/api/v1/goods-receipts/{$grnId}")
            ->assertOk()
            ->assertJsonPath('data.postingStatus.status', 'posted')
            ->assertJsonStructure([
                'data' => [
                    'postingStatus' => ['status', 'journalEntryId', 'journalNo', 'postedAt'],
                ],
            ]);
    }

    public function test_invoice_and_payment_include_posting_status(): void
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

        $this->getJson("/api/v1/purchase-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('data.postingStatus.status', 'posted');

        $this->getJson("/api/v1/supplier-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.postingStatus.status', 'posted');
    }
}
