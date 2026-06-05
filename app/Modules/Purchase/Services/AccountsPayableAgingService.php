<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\User;

final class AccountsPayableAgingService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return array{suppliers: array<int, array<string, mixed>>, totals: array<string, float>} */
    public function report(?User $actor, mixed $requestedOutletId): array
    {
        $query = PurchaseInvoice::query()
            ->with('supplier')
            ->whereIn('status', ['approved', 'partially_paid']);

        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId);

        $suppliers = [];
        $totals = [
            'current' => 0.0,
            'days1to30' => 0.0,
            'days31to60' => 0.0,
            'days61to90' => 0.0,
            'days90plus' => 0.0,
            'total' => 0.0,
        ];

        foreach ($query->get() as $invoice) {
            $outstanding = max(0, (float) $invoice->total_amount - (float) $invoice->paid_amount);
            if ($outstanding <= 0) {
                continue;
            }

            $bucket = $this->bucketForInvoice($invoice);
            $supplierId = (int) ($invoice->supplier_id ?? 0);

            if (! isset($suppliers[$supplierId])) {
                $supplier = $invoice->supplier ?? Supplier::query()->find($supplierId);
                $suppliers[$supplierId] = [
                    'supplierId' => (string) $supplierId,
                    'supplierName' => $supplier?->name ?? 'Unknown Supplier',
                    'current' => 0.0,
                    'days1to30' => 0.0,
                    'days31to60' => 0.0,
                    'days61to90' => 0.0,
                    'days90plus' => 0.0,
                    'total' => 0.0,
                ];
            }

            $suppliers[$supplierId][$bucket] += $outstanding;
            $suppliers[$supplierId]['total'] += $outstanding;
            $totals[$bucket] += $outstanding;
            $totals['total'] += $outstanding;
        }

        return [
            'suppliers' => array_values(array_map(static function (array $row): array {
                foreach (['current', 'days1to30', 'days31to60', 'days61to90', 'days90plus', 'total'] as $key) {
                    $row[$key] = round((float) $row[$key], 2);
                }

                return $row;
            }, $suppliers)),
            'totals' => array_map(static fn (float $v): float => round($v, 2), $totals),
        ];
    }

    private function bucketForInvoice(PurchaseInvoice $invoice): string
    {
        if ($invoice->due_date === null || $invoice->due_date->isFuture() || $invoice->due_date->isToday()) {
            return 'current';
        }

        $daysPastDue = (int) $invoice->due_date->diffInDays(now()->startOfDay());

        return match (true) {
            $daysPastDue <= 30 => 'days1to30',
            $daysPastDue <= 60 => 'days31to60',
            $daysPastDue <= 90 => 'days61to90',
            default => 'days90plus',
        };
    }
}
