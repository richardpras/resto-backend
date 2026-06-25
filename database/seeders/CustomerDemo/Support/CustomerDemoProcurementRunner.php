<?php

namespace Database\Seeders\CustomerDemo\Support;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\User;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Purchase\Services\GoodsReceivingLifecycleService;
use App\Modules\Purchase\Services\PurchaseOrderLifecycleService;
use App\Modules\Purchase\Services\SupplierInvoiceService;
use App\Modules\Purchase\Services\SupplierPaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\CustomerDemo\CustomerDemoContext;
use Illuminate\Support\Facades\DB;

final class CustomerDemoProcurementRunner
{
    public function __construct(
        private readonly PurchaseRequestService $purchaseRequests,
        private readonly PurchaseOrderLifecycleService $purchaseOrders,
        private readonly GoodsReceivingLifecycleService $goodsReceipts,
        private readonly SupplierInvoiceService $invoices,
        private readonly SupplierPaymentService $payments,
    ) {}

    /**
     * @param  array{
     *   index:int,
     *   prNo:string,
     *   date:CarbonImmutable,
     *   grnPercent?:float,
     *   invoicePercent?:float,
     *   paymentPercent?:float,
     *   poOnly?:bool
     * }  $spec
     */
    public function runScenario(User $actor, array $spec): void
    {
        $outletId = CustomerDemoContext::outletId();
        if ($this->scenarioAlreadySeeded($outletId, (string) $spec['prNo'])) {
            return;
        }

        $supplierId = (int) CustomerDemoContext::$supplierId;
        $warehouseId = (int) CustomerDemoContext::$warehouseId;
        $ingredient = $this->resolveIngredient($outletId, $spec['index']);

        $pr = $this->purchaseRequests->create($actor, [
            'outletId' => $outletId,
            'requestedBy' => 'Manager WR WB',
            'notes' => $spec['prNo'],
            'items' => [[
                'inventoryItemId' => (int) $ingredient->id,
                'quantity' => 50,
                'unit' => 'kg',
                'estimatedCost' => 12000,
            ]],
        ]);

        DB::table('purchase_requests_v2')->where('id', $pr->id)->update([
            'request_no' => $spec['prNo'],
            'created_at' => $spec['date'],
            'updated_at' => $spec['date'],
        ]);

        $this->purchaseRequests->submit($pr, $actor);
        $this->purchaseRequests->approve($pr->fresh(), $actor);

        $po = $this->purchaseRequests->convertToPurchaseOrder($pr->fresh(), $actor, [
            'supplierId' => $supplierId,
            'items' => [[
                'inventoryItemId' => (int) $ingredient->id,
                'quantity' => 50,
                'unitPrice' => 12000,
            ]],
        ]);

        $poNumber = sprintf('WRWB-PO-202605-%02d', $spec['index']);
        DB::table('purchase_orders')->where('id', $po->id)->update([
            'number' => $poNumber,
            'order_date' => $spec['date']->toDateString(),
            'created_at' => $spec['date'],
            'updated_at' => $spec['date'],
        ]);

        $this->purchaseOrders->submit($po->fresh(), $actor);
        $this->purchaseOrders->approve($po->fresh(), $actor);

        if (! empty($spec['poOnly'])) {
            return;
        }

        $orderedQty = 50.0;
        $grnPercent = (float) ($spec['grnPercent'] ?? 1.0);
        $receivedQty = round($orderedQty * $grnPercent, 2);
        if ($receivedQty <= 0) {
            return;
        }

        $grn = $this->goodsReceipts->create($actor, [
            'purchaseOrderId' => (int) $po->id,
            'warehouseId' => $warehouseId,
            'date' => $spec['date']->toDateString(),
            'items' => [[
                'inventoryItemId' => (int) $ingredient->id,
                'receivedQty' => $receivedQty,
            ]],
        ]);

        $grnNumber = sprintf('WRWB-GRN-202605-%02d', $spec['index']);
        DB::table('goods_receiving_notes')->where('id', $grn->id)->update(['number' => $grnNumber]);

        $this->goodsReceipts->receive($grn->fresh(), $actor);
        $this->goodsReceipts->post($grn->fresh(), $actor);

        $invoicePercent = $spec['invoicePercent'] ?? null;
        if ($invoicePercent === null || $invoicePercent <= 0) {
            return;
        }

        $invoiceQty = round($receivedQty * (float) $invoicePercent, 2);
        $invoice = $this->invoices->create($actor, [
            'purchaseOrderId' => (int) $po->id,
            'goodsReceiptId' => (int) $grn->id,
            'date' => $spec['date']->toDateString(),
            'dueDate' => $spec['date']->addDays(30)->toDateString(),
            'tax' => 0,
            'items' => [[
                'inventoryItemId' => (int) $ingredient->id,
                'qty' => $invoiceQty,
            ]],
        ]);

        $invNumber = sprintf('WRWB-INV-202605-%02d', $spec['index']);
        DB::table('purchase_invoices')->where('id', $invoice->id)->update(['number' => $invNumber]);

        $this->invoices->submit($invoice->fresh(), $actor);
        $this->invoices->approve($invoice->fresh(), $actor);

        $paymentPercent = (float) ($spec['paymentPercent'] ?? 0);
        if ($paymentPercent <= 0) {
            return;
        }

        $invoiceAmount = round($invoiceQty * 12000, 2);
        $payAmount = round($invoiceAmount * $paymentPercent, 2);
        if ($payAmount <= 0) {
            return;
        }

        $payment = $this->payments->create($actor, [
            'supplierId' => $supplierId,
            'outletId' => $outletId,
            'paymentDate' => $spec['date']->toDateString(),
            'paymentMethod' => 'cash',
            'amount' => $payAmount,
            'allocations' => [[
                'invoiceId' => (int) $invoice->id,
                'allocatedAmount' => $payAmount,
            ]],
        ]);

        $this->payments->approve($payment, $actor);
        $this->payments->post($payment->fresh(), $actor);
    }

    private function scenarioAlreadySeeded(int $outletId, string $prNo): bool
    {
        return PurchaseRequest::query()
            ->where('outlet_id', $outletId)
            ->where(function ($query) use ($prNo): void {
                $query->where('request_no', $prNo)
                    ->orWhere('notes', $prNo);
            })
            ->exists();
    }

    private function resolveIngredient(int $outletId, int $index): Ingredient
    {
        $names = Ingredient::query()
            ->where('outlet_id', $outletId)
            ->orderBy('id')
            ->pluck('name', 'id');

        $id = (int) ($names->keys()->get(($index - 1) % max(1, $names->count())) ?? 0);

        return Ingredient::query()->findOrFail($id);
    }
}
