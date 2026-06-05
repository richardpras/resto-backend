<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNoteItem;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseInvoiceItem;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SupplierInvoiceService
{
    /** @var list<string> */
    private const COMMITTED_STATUSES = ['submitted', 'approved', 'partially_paid', 'paid'];

    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ThreeWayMatchService $threeWayMatchService,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($actor, $data): PurchaseInvoice {
            [$po, $gr] = $this->resolveInvoiceSources($actor, (int) $data['purchaseOrderId'], (int) $data['goodsReceiptId']);
            $this->assertGrnInvoiceable($gr);

            $lines = $this->resolveInvoiceLines($gr, collect($data['items'] ?? []), null);
            $totals = $this->calculateTotals($lines, $data);

            $supplier = Supplier::query()->find((int) ($data['supplierId'] ?? $po->supplier_id));
            $dueDate = $this->resolveDueDate($data, $supplier, (string) $data['date']);

            $invoice = PurchaseInvoice::query()->create([
                'purchase_order_id' => $po->id,
                'goods_receiving_note_id' => $gr->id,
                'supplier_id' => $supplier?->id ?? $po->supplier_id,
                'outlet_id' => $po->outlet_id,
                'number' => $this->nextNumber(),
                'supplier_invoice_no' => $data['supplierInvoiceNo'] ?? null,
                'invoice_date' => $data['date'],
                'due_date' => $dueDate,
                'subtotal' => $totals['subtotal'],
                'tax_amount' => $totals['taxAmount'],
                'tax_percentage' => $totals['taxPercentage'],
                'discount_amount' => $totals['discountAmount'],
                'total_amount' => $totals['totalAmount'],
                'total' => $totals['totalAmount'],
                'tax' => $totals['taxAmount'],
                'paid_amount' => 0,
                'outstanding_amount' => $totals['totalAmount'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncItems($invoice, $lines);

            $fresh = $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier']);
            $this->purchaseAuditService->logPurchaseInvoiceLifecycle('created', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
                'totalAmount' => $totals['totalAmount'],
            ]);

            return $fresh;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(PurchaseInvoice $invoice, User $actor, array $data): PurchaseInvoice
    {
        $this->assertInvoiceOutlet($actor, $invoice);
        abort_if($invoice->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft invoices can be edited.');

        return DB::transaction(function () use ($invoice, $actor, $data): PurchaseInvoice {
            $po = PurchaseOrder::query()->with('items')->findOrFail((int) $invoice->purchase_order_id);
            $gr = GoodsReceivingNote::query()->with('items.purchaseOrderItem')->findOrFail((int) $invoice->goods_receiving_note_id);
            $this->assertGrnInvoiceable($gr);

            if (array_key_exists('date', $data)) {
                $invoice->invoice_date = $data['date'];
            }
            if (array_key_exists('supplierInvoiceNo', $data)) {
                $invoice->supplier_invoice_no = $data['supplierInvoiceNo'];
            }
            if (array_key_exists('notes', $data)) {
                $invoice->notes = $data['notes'];
            }
            if (array_key_exists('dueDate', $data)) {
                $invoice->due_date = $data['dueDate'];
            }

            if (array_key_exists('items', $data)) {
                $invoice->items()->delete();
                $lines = $this->resolveInvoiceLines($gr, collect($data['items']), (int) $invoice->id);
                $totals = $this->calculateTotals($lines, $data);
                $invoice->fill([
                    'subtotal' => $totals['subtotal'],
                    'tax_amount' => $totals['taxAmount'],
                    'tax_percentage' => $totals['taxPercentage'],
                    'discount_amount' => $totals['discountAmount'],
                    'total_amount' => $totals['totalAmount'],
                    'total' => $totals['totalAmount'],
                    'tax' => $totals['taxAmount'],
                    'outstanding_amount' => $totals['totalAmount'],
                ]);
                $this->syncItems($invoice, $lines);
            } elseif (array_key_exists('tax', $data) || array_key_exists('taxPercentage', $data) || array_key_exists('discountAmount', $data)) {
                $lines = $this->itemsToLineCollection($invoice->items);
                $totals = $this->calculateTotals($lines, $data);
                $invoice->fill([
                    'subtotal' => $totals['subtotal'],
                    'tax_amount' => $totals['taxAmount'],
                    'tax_percentage' => $totals['taxPercentage'],
                    'discount_amount' => $totals['discountAmount'],
                    'total_amount' => $totals['totalAmount'],
                    'total' => $totals['totalAmount'],
                    'tax' => $totals['taxAmount'],
                    'outstanding_amount' => $totals['totalAmount'],
                ]);
            }

            if (! array_key_exists('dueDate', $data) && array_key_exists('date', $data)) {
                $supplier = Supplier::query()->find((int) ($invoice->supplier_id ?? $po->supplier_id));
                $invoice->due_date = $this->resolveDueDate([], $supplier, (string) $data['date']);
            }

            $invoice->save();

            return $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier']);
        });
    }

    public function submit(PurchaseInvoice $invoice, User $actor): PurchaseInvoice
    {
        $this->assertInvoiceOutlet($actor, $invoice);
        abort_if($invoice->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft invoices can be submitted.');
        $invoice->loadCount('items');
        abort_if($invoice->items_count < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot submit an invoice without lines.');

        $gr = GoodsReceivingNote::query()->with('items.purchaseOrderItem')->findOrFail((int) $invoice->goods_receiving_note_id);
        $this->validateCommittedLines($gr, $invoice);

        return DB::transaction(function () use ($invoice, $actor): PurchaseInvoice {
            $invoice->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            $fresh = $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier']);
            $this->purchaseAuditService->logPurchaseInvoiceLifecycle('submitted', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);
            $this->threeWayMatchService->validateInvoice($fresh, $actor);

            return $fresh;
        });
    }

    public function approve(PurchaseInvoice $invoice, User $actor): PurchaseInvoice
    {
        $this->assertInvoiceOutlet($actor, $invoice);
        abort_if($invoice->status !== 'submitted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only submitted invoices can be approved.');
        abort_if((float) $invoice->total_amount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot approve invoice with zero or negative total.');

        $gr = GoodsReceivingNote::query()->with('items.purchaseOrderItem')->findOrFail((int) $invoice->goods_receiving_note_id);
        $this->validateCommittedLines($gr, $invoice);
        $this->threeWayMatchService->assertInvoiceApprovable($invoice, $actor);

        return DB::transaction(function () use ($invoice, $actor): PurchaseInvoice {
            $invoice->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'outstanding_amount' => $this->calculateOutstanding($invoice),
            ]);

            $fresh = $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier']);
            $this->purchaseAuditService->logPurchaseInvoiceLifecycle('approved', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
                'outstandingAmount' => (float) $fresh->outstanding_amount,
            ]);

            return $fresh;
        });
    }

    public function void(PurchaseInvoice $invoice, User $actor): PurchaseInvoice
    {
        $this->assertInvoiceOutlet($actor, $invoice);
        abort_if($invoice->status === 'void', Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice is already void.');
        abort_if(in_array($invoice->status, ['partially_paid', 'paid'], true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Paid invoices cannot be voided.');

        return DB::transaction(function () use ($invoice, $actor): PurchaseInvoice {
            $invoice->update([
                'status' => 'void',
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'outstanding_amount' => 0,
            ]);

            $fresh = $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments', 'items', 'supplier']);
            $this->purchaseAuditService->logPurchaseInvoiceLifecycle('voided', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'number' => $fresh->number,
            ]);

            return $fresh;
        });
    }

    public function calculateOutstanding(PurchaseInvoice $invoice): float
    {
        if ($invoice->status === 'void') {
            return 0.0;
        }

        $paid = (float) $invoice->paid_amount;
        if ($paid <= 0 && $invoice->relationLoaded('payments')) {
            $paid = (float) $invoice->payments->sum('amount');
        } elseif ($paid <= 0) {
            $paid = (float) $invoice->payments()->sum('amount');
        }

        return max(0, (float) $invoice->total_amount - $paid);
    }

    /** @return array{outstandingAmount:float,paidAmount:float,totalAmount:float,grnRemainingValue:float,grnInvoicedValue:float} */
    public function outstandingDetails(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing(['items', 'goodsReceivingNote.items', 'payments']);
        $gr = $invoice->goodsReceivingNote;
        $grnValue = $gr
            ? (float) $gr->items->sum(static fn (GoodsReceivingNoteItem $item): float => (float) $item->received_qty * (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0))
            : 0.0;
        $grnInvoiced = $gr
            ? $this->invoicedValueForGrn((int) $gr->id, (int) $invoice->id)
            : 0.0;

        $paid = (float) $invoice->payments->sum('amount');

        return [
            'outstandingAmount' => $this->calculateOutstanding($invoice),
            'paidAmount' => $paid > 0 ? $paid : (float) $invoice->paid_amount,
            'totalAmount' => (float) $invoice->total_amount,
            'grnRemainingValue' => max(0, $grnValue - $grnInvoiced),
            'grnInvoicedValue' => $grnInvoiced,
        ];
    }

    /** @return array{0:PurchaseOrder,1:GoodsReceivingNote} */
    private function resolveInvoiceSources(User $actor, int $purchaseOrderId, int $goodsReceiptId): array
    {
        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::query()->with('items')->find($purchaseOrderId);
        /** @var GoodsReceivingNote|null $gr */
        $gr = GoodsReceivingNote::query()->with('items.purchaseOrderItem')->find($goodsReceiptId);

        abort_if($po === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
        abort_if($gr === null, Response::HTTP_NOT_FOUND, 'Goods receipt not found.');
        abort_if((int) $gr->purchase_order_id !== (int) $po->id, Response::HTTP_UNPROCESSABLE_ENTITY, 'Goods receipt must belong to selected purchase order.');
        $this->purchaseScopeService->assertDocumentOutlet($actor, $po->outlet_id !== null ? (int) $po->outlet_id : null);

        return [$po, $gr];
    }

    private function assertGrnInvoiceable(GoodsReceivingNote $gr): void
    {
        abort_if($gr->status !== 'posted', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only posted goods receipts can be invoiced.');
    }

    private function assertInvoiceOutlet(User $actor, PurchaseInvoice $invoice): void
    {
        $this->purchaseScopeService->assertDocumentOutlet(
            $actor,
            $invoice->outlet_id !== null ? (int) $invoice->outlet_id : null
        );
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $requestedLines
     * @return Collection<int, array<string,mixed>>
     */
    private function resolveInvoiceLines(GoodsReceivingNote $gr, Collection $requestedLines, ?int $excludeInvoiceId): Collection
    {
        $alreadyInvoicedByGrnItem = $this->committedQtyByGrnItem((int) $gr->id, $excludeInvoiceId);
        $lines = collect();

        if ($requestedLines->isEmpty()) {
            foreach ($gr->items as $grnItem) {
                $remaining = $this->remainingInvoiceQty($grnItem, $alreadyInvoicedByGrnItem);
                if ($remaining <= 0) {
                    continue;
                }
                $lines->push($this->lineFromGrnItem($grnItem, $remaining));
            }

            return $lines;
        }

        foreach ($requestedLines as $requested) {
            $ingredientId = (int) $requested['inventoryItemId'];
            $qty = (float) ($requested['invoicedQty'] ?? $requested['qty'] ?? 0);

            /** @var GoodsReceivingNoteItem|null $grnItem */
            $grnItem = $gr->items->firstWhere('ingredient_id', $ingredientId);
            abort_if($grnItem === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice item is not part of selected goods receipt.');

            $remaining = $this->remainingInvoiceQty($grnItem, $alreadyInvoicedByGrnItem);
            abort_if($qty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice exceeds received quantity/value.');
            abort_if($qty <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice quantity must be greater than zero.');

            $line = $this->lineFromGrnItem($grnItem, $qty);
            if (array_key_exists('unitCost', $requested) && $requested['unitCost'] !== null) {
                $line['unit_cost'] = (float) $requested['unitCost'];
                $line['line_subtotal'] = $qty * (float) $requested['unitCost'];
                $line['line_total'] = $line['line_subtotal'];
            }
            $lines->push($line);
        }

        $this->assertValueWithinGrnRemaining($gr, $lines, $excludeInvoiceId);

        return $lines;
    }

    /** @param Collection<int, array<string,mixed>> $lines */
    private function assertValueWithinGrnRemaining(GoodsReceivingNote $gr, Collection $lines, ?int $excludeInvoiceId): void
    {
        $grnTotalValue = (float) $gr->items->sum(
            static fn (GoodsReceivingNoteItem $item): float => (float) $item->received_qty * (float) ($item->actual_received_cost ?? $item->original_po_cost ?? 0)
        );
        $alreadyInvoicedValue = $this->invoicedValueForGrn((int) $gr->id, $excludeInvoiceId);
        $thisInvoiceValue = (float) $lines->sum(static fn (array $line): float => (float) $line['line_subtotal']);

        abort_if(
            ($alreadyInvoicedValue + $thisInvoiceValue) > $grnTotalValue + 0.0001,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Invoice exceeds received quantity/value.'
        );
    }

    private function validateCommittedLines(GoodsReceivingNote $gr, PurchaseInvoice $invoice): void
    {
        $invoice->loadMissing('items');
        $alreadyInvoicedByGrnItem = $this->committedQtyByGrnItem((int) $gr->id, (int) $invoice->id);

        foreach ($invoice->items as $item) {
            $grnItemId = (int) $item->goods_receiving_note_item_id;
            /** @var GoodsReceivingNoteItem|null $grnItem */
            $grnItem = $gr->items->firstWhere('id', $grnItemId);
            if ($grnItem === null) {
                continue;
            }
            $remaining = $this->remainingInvoiceQty($grnItem, $alreadyInvoicedByGrnItem);
            abort_if((float) $item->invoiced_qty > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice exceeds received quantity/value.');
        }

        $this->assertValueWithinGrnRemaining($gr, $this->itemsToLineCollection($invoice->items), (int) $invoice->id);
    }

    /** @return Collection<int|string, float> */
    private function committedQtyByGrnItem(int $grnId, ?int $excludeInvoiceId): Collection
    {
        $query = PurchaseInvoiceItem::query()
            ->whereHas('purchaseInvoice', function ($q) use ($grnId, $excludeInvoiceId): void {
                $q->where('goods_receiving_note_id', $grnId)
                    ->whereIn('status', self::COMMITTED_STATUSES);
                if ($excludeInvoiceId !== null) {
                    $q->where('id', '!=', $excludeInvoiceId);
                }
            })
            ->whereNotNull('goods_receiving_note_item_id');

        return $query
            ->selectRaw('goods_receiving_note_item_id, SUM(COALESCE(invoiced_qty, qty)) as invoiced_qty')
            ->groupBy('goods_receiving_note_item_id')
            ->pluck('invoiced_qty', 'goods_receiving_note_item_id');
    }

    private function invoicedValueForGrn(int $grnId, ?int $excludeInvoiceId): float
    {
        $query = PurchaseInvoiceItem::query()
            ->whereHas('purchaseInvoice', function ($q) use ($grnId, $excludeInvoiceId): void {
                $q->where('goods_receiving_note_id', $grnId)
                    ->whereIn('status', self::COMMITTED_STATUSES);
                if ($excludeInvoiceId !== null) {
                    $q->where('id', '!=', $excludeInvoiceId);
                }
            });

        return (float) $query->get()->sum(static function (PurchaseInvoiceItem $item): float {
            return (float) ($item->line_subtotal > 0
                ? $item->line_subtotal
                : (float) ($item->invoiced_qty ?? $item->qty) * (float) ($item->unit_cost ?? $item->unit_price ?? 0));
        });
    }

    /** @param Collection<int|string, mixed> $alreadyInvoicedByGrnItem */
    private function remainingInvoiceQty(GoodsReceivingNoteItem $grnItem, Collection $alreadyInvoicedByGrnItem): float
    {
        $received = (float) $grnItem->received_qty;
        $alreadyInvoiced = (float) ($alreadyInvoicedByGrnItem->get($grnItem->id) ?? 0);

        return max(0, $received - $alreadyInvoiced);
    }

    /** @return array<string,mixed> */
    private function lineFromGrnItem(GoodsReceivingNoteItem $grnItem, float $qty): array
    {
        $unitCost = (float) ($grnItem->actual_received_cost ?? $grnItem->original_po_cost ?? $grnItem->purchaseOrderItem?->unit_price ?? 0);
        $lineSubtotal = $qty * $unitCost;

        return [
            'goods_receiving_note_item_id' => (int) $grnItem->id,
            'ingredient_id' => (int) $grnItem->ingredient_id,
            'received_qty' => (float) $grnItem->received_qty,
            'invoiced_qty' => $qty,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'unit_price' => $unitCost,
            'line_subtotal' => $lineSubtotal,
            'line_tax_amount' => 0,
            'line_total' => $lineSubtotal,
        ];
    }

    /** @param Collection<int, array<string,mixed>> $lines
     * @param array<string,mixed> $data
     * @return array{subtotal:float,taxAmount:float,taxPercentage:?float,discountAmount:float,totalAmount:float}
     */
    private function calculateTotals(Collection $lines, array $data): array
    {
        $subtotal = (float) $lines->sum(static fn (array $line): float => (float) $line['line_subtotal']);
        abort_if($subtotal <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice quantity must be greater than zero.');

        $discountAmount = (float) ($data['discountAmount'] ?? 0);
        $taxPercentage = array_key_exists('taxPercentage', $data) && $data['taxPercentage'] !== null
            ? (float) $data['taxPercentage']
            : null;
        $taxAmount = array_key_exists('tax', $data)
            ? (float) ($data['tax'] ?? 0)
            : ($taxPercentage !== null ? round(($subtotal - $discountAmount) * ($taxPercentage / 100), 2) : 0.0);
        $totalAmount = max(0, $subtotal - $discountAmount + $taxAmount);
        abort_if($totalAmount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Cannot approve invoice with zero or negative total.');

        return [
            'subtotal' => $subtotal,
            'taxAmount' => $taxAmount,
            'taxPercentage' => $taxPercentage,
            'discountAmount' => $discountAmount,
            'totalAmount' => $totalAmount,
        ];
    }

    /** @param Collection<int, array<string,mixed>> $lines */
    private function syncItems(PurchaseInvoice $invoice, Collection $lines): void
    {
        foreach ($lines as $line) {
            PurchaseInvoiceItem::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'goods_receiving_note_item_id' => $line['goods_receiving_note_item_id'],
                'ingredient_id' => $line['ingredient_id'],
                'received_qty' => $line['received_qty'],
                'invoiced_qty' => $line['invoiced_qty'],
                'qty' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
                'unit_price' => $line['unit_price'],
                'line_subtotal' => $line['line_subtotal'],
                'line_tax_amount' => $line['line_tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }
    }

    /** @param \Illuminate\Support\Collection<int, PurchaseInvoiceItem> $items
     * @return Collection<int, array<string,mixed>>
     */
    private function itemsToLineCollection($items): Collection
    {
        return $items->map(static fn (PurchaseInvoiceItem $item): array => [
            'line_subtotal' => (float) ($item->line_subtotal > 0
                ? $item->line_subtotal
                : (float) ($item->invoiced_qty ?? $item->qty) * (float) ($item->unit_cost ?? $item->unit_price ?? 0)),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function resolveDueDate(array $data, ?Supplier $supplier, string $invoiceDate): string
    {
        if (! empty($data['dueDate'])) {
            return (string) $data['dueDate'];
        }

        $termDays = (int) ($supplier?->payment_term_days ?? 30);

        return date('Y-m-d', strtotime($invoiceDate.' +'.$termDays.' days'));
    }

    private function nextNumber(): string
    {
        $lastId = (int) (PurchaseInvoice::query()->max('id') ?? 0);

        return 'INV-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
