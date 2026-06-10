<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingHealthSnapshot;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\Adapters\AccountingNotificationAdapter;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class AccountingHealthSnapshotService
{
    public function __construct(
        private readonly AccountingHealthService $accountingHealthService,
        private readonly AccountingHealthSeverityEngine $severityEngine,
        private readonly AccountingNotificationAdapter $accountingNotificationAdapter,
    ) {}

    public function captureForOutlet(?int $outletId, ?Carbon $date = null): AccountingHealthSnapshot
    {
        $snapshotDate = ($date ?? now())->toDateString();
        $report = $this->accountingHealthService->report(null, $outletId);

        $previous = AccountingHealthSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('snapshot_date', '<', $snapshotDate)
            ->orderByDesc('snapshot_date')
            ->first();

        $snapshot = AccountingHealthSnapshot::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'snapshot_date' => $snapshotDate,
            ],
            [
                'posting_failures' => (int) ($report['failedPostings'] ?? 0),
                'gift_card_variance' => (float) ($report['giftCardVariance'] ?? 0),
                'inventory_variance' => (float) ($report['inventoryValuationDifference'] ?? 0),
                'payroll_variance' => (float) ($report['payrollVariance'] ?? 0),
                'procurement_variance' => (float) ($report['procurementVariance'] ?? 0),
                'severity' => (string) ($report['healthSeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY),
            ],
        );

        $currentSeverity = (string) $snapshot->severity;
        $previousSeverity = (string) ($previous?->severity ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);

        if ($outletId !== null && $outletId > 0 && $this->severityEngine->isWorsening($previousSeverity, $currentSeverity)) {
            $this->accountingNotificationAdapter->notifySeverityEscalation(
                $outletId,
                $previousSeverity,
                $currentSeverity,
                $report,
            );
        }

        return $snapshot->fresh();
    }

    public function captureAllOutlets(?Carbon $date = null): Collection
    {
        $outletIds = Outlet::query()->where('status', 'active')->pluck('id')->map(static fn ($id): int => (int) $id);

        return $outletIds->map(fn (int $outletId): AccountingHealthSnapshot => $this->captureForOutlet($outletId, $date));
    }

    /**
     * @return array{
     *   postingFailures: list<array{date:string,count:int}>,
     *   giftCardVariance: list<array{date:string,variance:float}>,
     *   inventoryVariance: list<array{date:string,variance:float}>,
     *   severityTrend: list<array{date:string,severity:string}>
     * }
     */
    public function trends(?int $outletId, string $startDate, string $endDate): array
    {
        $query = AccountingHealthSnapshot::query()
            ->whereBetween('snapshot_date', [$startDate, $endDate])
            ->orderBy('snapshot_date');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        } else {
            $query->whereNull('outlet_id');
        }

        $rows = $query->get();

        return [
            'postingFailures' => $rows->map(static fn (AccountingHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'count' => (int) $row->posting_failures,
            ])->values()->all(),
            'giftCardVariance' => $rows->map(static fn (AccountingHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'variance' => (float) $row->gift_card_variance,
            ])->values()->all(),
            'inventoryVariance' => $rows->map(static fn (AccountingHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'variance' => (float) $row->inventory_variance,
            ])->values()->all(),
            'severityTrend' => $rows->map(static fn (AccountingHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'severity' => (string) $row->severity,
            ])->values()->all(),
        ];
    }
}
