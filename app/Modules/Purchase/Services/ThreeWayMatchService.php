<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\ProcurementMatchConfig;
use App\Models\Modules\Purchase\Domain\ProcurementMatchResult;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseInvoiceItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

final class ThreeWayMatchService
{
    /** @var list<string> */
    public const APPROVABLE_STATUSES = ['matched', 'matched_with_tolerance'];

    /** @var list<string> */
    public const PAYABLE_STATUSES = ['matched', 'matched_with_tolerance'];

    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
    ) {}

    public function validateInvoice(PurchaseInvoice $invoice, ?User $actor = null, bool $isRevalidation = false): ProcurementMatchResult
    {
        $invoice->loadMissing([
            'items.goodsReceivingNoteItem.purchaseOrderItem',
            'purchaseOrder.items',
            'goodsReceivingNote.items.purchaseOrderItem',
        ]);

        $differences = $this->calculateDifferences($invoice);
        if ($differences['blocked']) {
            $result = $this->createMatchResult($invoice, 'blocked', $differences, $actor, $differences['notes']);
            $this->purchaseAuditService->logProcurementMatch('failed', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => 'blocked',
                'notes' => $differences['notes'],
            ]);

            return $result;
        }

        $config = $this->resolveConfig((int) $invoice->outlet_id);
        $status = $this->resolveMatchStatus($differences, $config);
        $result = $this->createMatchResult($invoice, $status, $differences, $actor);

        if ($isRevalidation) {
            $this->purchaseAuditService->logProcurementMatch('revalidated', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => $status,
                'qtyDifference' => $differences['qty_difference'],
                'priceDifference' => $differences['price_difference'],
                'amountDifference' => $differences['amount_difference'],
            ]);
        } else {
            $this->purchaseAuditService->logProcurementMatch('created', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => $status,
            ]);
        }

        if (! in_array($status, self::APPROVABLE_STATUSES, true)) {
            $this->purchaseAuditService->logProcurementMatch('failed', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => $status,
            ]);
        }

        return $result;
    }

    public function assertInvoiceApprovable(PurchaseInvoice $invoice, ?User $actor = null): ProcurementMatchResult
    {
        $latest = $this->latestResultForInvoice((int) $invoice->id);
        if ($latest === null || $invoice->status === 'submitted') {
            $latest = $this->validateInvoice($invoice, $actor);
        }

        if (! in_array($latest->match_status, self::APPROVABLE_STATUSES, true)) {
            $this->purchaseAuditService->logProcurementMatch('invoice_approval_blocked', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => $latest->match_status,
            ]);
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice failed three-way match.');
        }

        return $latest;
    }

    public function validatePayment(PurchaseInvoice $invoice, ?User $actor = null): void
    {
        $latest = $this->latestResultForInvoice((int) $invoice->id);
        if ($latest === null) {
            $latest = $this->validateInvoice($invoice, $actor);
        }

        if (! in_array($latest->match_status, self::PAYABLE_STATUSES, true)) {
            $this->purchaseAuditService->logProcurementMatch('payment_blocked', (int) $invoice->id, (int) $invoice->outlet_id, $actor, [
                'matchStatus' => $latest->match_status,
                'invoiceId' => (int) $invoice->id,
            ]);
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice must pass three-way matching before payment.');
        }
    }

    /** @return array{blocked:bool,qty_difference:float,price_difference:float,amount_difference:float,qty_within_tolerance:bool,price_within_tolerance:bool,amount_within_tolerance:bool,notes:?string} */
    public function calculateDifferences(PurchaseInvoice $invoice): array
    {
        if ($invoice->purchase_order_id === null || $invoice->goods_receiving_note_id === null) {
            return $this->blockedDifference('Purchase order and goods receipt are required for three-way match.');
        }

        $gr = $invoice->goodsReceivingNote;
        if ($gr === null) {
            return $this->blockedDifference('Goods receipt not found.');
        }

        if ($gr->status !== 'posted') {
            return $this->blockedDifference('Goods receipt must be posted before matching.');
        }

        $invoice->loadMissing('items.goodsReceivingNoteItem.purchaseOrderItem');
        if ($invoice->items->isEmpty()) {
            return $this->blockedDifference('Invoice has no lines to match.');
        }

        $qtyOverrun = 0.0;
        $maxPriceDeviationPercent = 0.0;
        $expectedSubtotal = 0.0;
        $actualSubtotal = (float) ($invoice->subtotal > 0 ? $invoice->subtotal : $invoice->items->sum(
            static fn (PurchaseInvoiceItem $item): float => (float) ($item->line_subtotal > 0
                ? $item->line_subtotal
                : (float) ($item->invoiced_qty ?? $item->qty) * (float) ($item->unit_cost ?? $item->unit_price ?? 0))
        ));

        foreach ($invoice->items as $item) {
            $invoicedQty = (float) ($item->invoiced_qty ?? $item->qty ?? 0);
            $receivedQty = (float) ($item->received_qty ?? $item->goodsReceivingNoteItem?->received_qty ?? 0);
            $qtyOverrun += max(0, $invoicedQty - $receivedQty);

            $poUnitPrice = (float) ($item->goodsReceivingNoteItem?->purchaseOrderItem?->unit_price
                ?? $item->goodsReceivingNoteItem?->original_po_cost
                ?? $item->unit_cost
                ?? 0);
            $invoiceUnitCost = (float) ($item->unit_cost ?? $item->unit_price ?? 0);

            if ($poUnitPrice > 0) {
                $priceDeviation = abs($invoiceUnitCost - $poUnitPrice) / $poUnitPrice * 100;
                $maxPriceDeviationPercent = max($maxPriceDeviationPercent, $priceDeviation);
            } elseif ($invoiceUnitCost > 0) {
                $maxPriceDeviationPercent = 100.0;
            }

            $expectedSubtotal += $invoicedQty * $poUnitPrice;
        }

        $amountDifference = $actualSubtotal - $expectedSubtotal;
        $amountDeviationPercent = $expectedSubtotal > 0
            ? abs($amountDifference) / $expectedSubtotal * 100
            : ($actualSubtotal > 0 ? 100.0 : 0.0);

        $config = $this->resolveConfig((int) $invoice->outlet_id);

        return [
            'blocked' => false,
            'qty_difference' => round($qtyOverrun, 4),
            'price_difference' => round($maxPriceDeviationPercent, 4),
            'amount_difference' => round($amountDifference, 4),
            'qty_within_tolerance' => $qtyOverrun <= 0.0001,
            'price_within_tolerance' => $maxPriceDeviationPercent <= (float) $config->price_tolerance_percent + 0.0001,
            'amount_within_tolerance' => $amountDeviationPercent <= (float) $config->amount_tolerance_percent + 0.0001,
            'notes' => null,
        ];
    }

    /** @param array<string,mixed> $differences */
    public function createMatchResult(
        PurchaseInvoice $invoice,
        string $status,
        array $differences,
        ?User $actor = null,
        ?string $notes = null,
    ): ProcurementMatchResult {
        return ProcurementMatchResult::query()->create([
            'purchase_order_id' => (int) $invoice->purchase_order_id,
            'goods_receipt_id' => (int) $invoice->goods_receiving_note_id,
            'invoice_id' => (int) $invoice->id,
            'match_status' => $status,
            'qty_difference' => (float) ($differences['qty_difference'] ?? 0),
            'price_difference' => (float) ($differences['price_difference'] ?? 0),
            'amount_difference' => (float) ($differences['amount_difference'] ?? 0),
            'matched_at' => now(),
            'matched_by' => $actor?->id,
            'notes' => $notes ?? ($differences['notes'] ?? null),
        ]);
    }

    public function latestResultForInvoice(int $invoiceId): ?ProcurementMatchResult
    {
        return ProcurementMatchResult::query()
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, ProcurementMatchResult> */
    public function listLatestResults(?User $actor, mixed $requestedOutletId, ?string $status = null): Collection
    {
        $query = ProcurementMatchResult::query()
            ->with(['purchaseOrder', 'goodsReceivingNote', 'purchaseInvoice'])
            ->whereIn('id', function ($sub): void {
                $sub->selectRaw('MAX(id)')
                    ->from('procurement_match_results')
                    ->groupBy('invoice_id');
            });

        if ($status !== null && $status !== '') {
            $query->where('match_status', $status);
        }

        $query->whereHas('purchaseInvoice', function ($invoiceQuery) use ($actor, $requestedOutletId): void {
            $this->purchaseScopeService->applyOutletScope($invoiceQuery, $actor, $requestedOutletId);
        });

        return $query->orderByDesc('matched_at')->get();
    }

    public function resolveConfig(int $outletId): ProcurementMatchConfig
    {
        $config = ProcurementMatchConfig::query()
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->first();

        if ($config !== null) {
            return $config;
        }

        return ProcurementMatchConfig::query()->create([
            'outlet_id' => $outletId,
            'quantity_tolerance_percent' => 0,
            'price_tolerance_percent' => 3,
            'amount_tolerance_percent' => 3,
            'auto_approve_within_tolerance' => true,
            'is_active' => true,
        ]);
    }

    /** @param array<string,mixed> $differences */
    private function resolveMatchStatus(array $differences, ProcurementMatchConfig $config): string
    {
        $qtyOk = (bool) ($differences['qty_within_tolerance'] ?? false);
        $priceOk = (bool) ($differences['price_within_tolerance'] ?? false);
        $amountOk = (bool) ($differences['amount_within_tolerance'] ?? false);

        if (! $qtyOk || ! $priceOk || ! $amountOk) {
            return 'mismatch';
        }

        $qtyExact = ((float) ($differences['qty_difference'] ?? 0)) <= 0.0001;
        $priceExact = ((float) ($differences['price_difference'] ?? 0)) <= 0.0001;
        $amountExact = abs((float) ($differences['amount_difference'] ?? 0)) <= 0.01;

        if ($qtyExact && $priceExact && $amountExact) {
            return 'matched';
        }

        return 'matched_with_tolerance';
    }

    /** @return array{blocked:bool,qty_difference:float,price_difference:float,amount_difference:float,qty_within_tolerance:bool,price_within_tolerance:bool,amount_within_tolerance:bool,notes:string} */
    private function blockedDifference(string $notes): array
    {
        return [
            'blocked' => true,
            'qty_difference' => 0.0,
            'price_difference' => 0.0,
            'amount_difference' => 0.0,
            'qty_within_tolerance' => false,
            'price_within_tolerance' => false,
            'amount_within_tolerance' => false,
            'notes' => $notes,
        ];
    }
}
