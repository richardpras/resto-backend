<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;

final class AccountsPayableSummaryService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function supplierPayables(?User $actor, mixed $requestedOutletId): array
    {
        $query = PurchaseInvoice::query()
            ->with('supplier')
            ->whereIn('status', ['approved', 'partially_paid']);

        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        $invoices = $query->get();

        return $invoices
            ->groupBy('supplier_id')
            ->map(function (Collection $group, $supplierId): array {
                /** @var PurchaseInvoice $latest */
                $latest = $group->sortByDesc('invoice_date')->first();
                $outstanding = (float) $group->sum(static fn (PurchaseInvoice $inv): float => max(
                    0,
                    (float) $inv->total_amount - (float) $inv->paid_amount
                ));
                $overdue = (float) $group
                    ->filter(static fn (PurchaseInvoice $inv): bool => $inv->due_date !== null && $inv->due_date->isPast())
                    ->sum(static fn (PurchaseInvoice $inv): float => max(
                        0,
                        (float) $inv->total_amount - (float) $inv->paid_amount
                    ));

                $supplier = $latest->supplier ?? Supplier::query()->find($supplierId);

                return [
                    'supplierId' => (string) ($supplierId ?? ''),
                    'supplierName' => $supplier?->name ?? 'Unknown Supplier',
                    'invoiceCount' => $group->count(),
                    'outstandingBalance' => round($outstanding, 2),
                    'overdueBalance' => round($overdue, 2),
                    'lastInvoiceDate' => optional($latest->invoice_date)->format('Y-m-d'),
                    'lastInvoiceNumber' => $latest->number,
                ];
            })
            ->values()
            ->sortByDesc('outstandingBalance')
            ->values()
            ->all();
    }

    public function outstandingPayablesTotal(?User $actor, mixed $requestedOutletId): float
    {
        $query = PurchaseInvoice::query()->whereIn('status', ['approved', 'partially_paid']);
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return (float) $query->get()->sum(static fn (PurchaseInvoice $inv): float => max(
            0,
            (float) $inv->total_amount - (float) $inv->paid_amount
        ));
    }

    public function overdueInvoicesCount(?User $actor, mixed $requestedOutletId): int
    {
        $query = PurchaseInvoice::query()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->whereDate('due_date', '<', now()->toDateString());

        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        return (int) $query->count();
    }
}
