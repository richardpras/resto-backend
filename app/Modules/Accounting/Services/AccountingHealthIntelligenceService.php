<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use Carbon\Carbon;

final class AccountingHealthIntelligenceService
{
    public function __construct(
        private readonly AccountingHealthSeverityEngine $severityEngine,
    ) {}

    /**
     * @return array{
     *   ageMinutes:int,
     *   ageHours:float,
     *   ageDays:float,
     *   agingBucket:string
     * }
     */
    public function agingForFailure(AccountingPostingFailure $failure): array
    {
        $created = $failure->created_at ?? now();
        $ageMinutes = (int) max(0, $created->diffInMinutes(now()));

        return [
            'ageMinutes' => $ageMinutes,
            'ageHours' => round($ageMinutes / 60, 2),
            'ageDays' => round($ageMinutes / 1440, 2),
            'agingBucket' => $this->agingBucket($ageMinutes),
        ];
    }

    public function agingBucket(int $ageMinutes): string
    {
        return match (true) {
            $ageMinutes < 60 => '0-1h',
            $ageMinutes < 240 => '1-4h',
            $ageMinutes < 1440 => '4-24h',
            $ageMinutes < 4320 => '1-3d',
            default => '3d+',
        };
    }

    /**
     * @return array<string, int>
     */
    public function failureAgingBuckets(?int $outletId = null): array
    {
        $buckets = [
            '0-1h' => 0,
            '1-4h' => 0,
            '4-24h' => 0,
            '1-3d' => 0,
            '3d+' => 0,
        ];

        $query = AccountingPostingFailure::query()
            ->where('status', AccountingPostingFailure::STATUS_PENDING);

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        foreach ($query->get(['id', 'created_at']) as $failure) {
            $bucket = $this->agingForFailure($failure)['agingBucket'];
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * @return list<array{sourceType:string,count:int}>
     */
    public function topFailureSources(?int $outletId = null, int $limit = 10): array
    {
        $query = AccountingPostingFailure::query()
            ->selectRaw('source_type, COUNT(*) as aggregate')
            ->where('status', AccountingPostingFailure::STATUS_PENDING)
            ->groupBy('source_type')
            ->orderByDesc('aggregate');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return $query->limit($limit)->get()->map(static fn ($row): array => [
            'sourceType' => (string) $row->source_type,
            'count' => (int) $row->aggregate,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $healthReport
     * @return list<array{priority:string,title:string,message:string,actionUrl:string}>
     */
    public function priorityQueue(array $healthReport): array
    {
        $items = [];

        $postingSeverity = (string) ($healthReport['postingFailuresSeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);
        $failedPostings = (int) ($healthReport['failedPostings'] ?? 0);
        if ($failedPostings > 0) {
            $items[] = [
                'priority' => $postingSeverity,
                'title' => 'Posting Failures',
                'message' => sprintf('%d pending posting failure(s) require attention.', $failedPostings),
                'actionUrl' => '/accounting?tab=health',
            ];
        }

        $giftSeverity = (string) ($healthReport['giftCardSeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($giftSeverity !== AccountingHealthSeverityEngine::SEVERITY_HEALTHY) {
            $items[] = [
                'priority' => $giftSeverity,
                'title' => 'Gift Card Variance',
                'message' => sprintf('Gift card variance %.2f.', (float) ($healthReport['giftCardVariance'] ?? 0)),
                'actionUrl' => '/accounting?tab=reconciliation',
            ];
        }

        $inventorySeverity = (string) ($healthReport['inventorySeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($inventorySeverity !== AccountingHealthSeverityEngine::SEVERITY_HEALTHY) {
            $items[] = [
                'priority' => $inventorySeverity,
                'title' => 'Inventory Valuation Variance',
                'message' => sprintf('Inventory valuation difference %.2f.', (float) ($healthReport['inventoryValuationDifference'] ?? 0)),
                'actionUrl' => '/accounting?tab=reconciliation',
            ];
        }

        $payrollSeverity = (string) ($healthReport['payrollSeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($payrollSeverity !== AccountingHealthSeverityEngine::SEVERITY_HEALTHY) {
            $items[] = [
                'priority' => $payrollSeverity,
                'title' => 'Payroll Reconciliation Variance',
                'message' => sprintf('Payroll reconciliation difference %.2f.', (float) ($healthReport['payrollVariance'] ?? 0)),
                'actionUrl' => '/accounting?tab=reconciliation',
            ];
        }

        $procurementSeverity = (string) ($healthReport['procurementSeverity'] ?? AccountingHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($procurementSeverity !== AccountingHealthSeverityEngine::SEVERITY_HEALTHY) {
            $items[] = [
                'priority' => $procurementSeverity,
                'title' => 'Procurement Reconciliation Variance',
                'message' => sprintf('Procurement reconciliation difference %.2f.', (float) ($healthReport['procurementVariance'] ?? 0)),
                'actionUrl' => '/accounting?tab=reconciliation',
            ];
        }

        usort($items, function (array $a, array $b): int {
            return $this->severityEngine->severityRank((string) $b['priority'])
                <=> $this->severityEngine->severityRank((string) $a['priority']);
        });

        return $items;
    }

    /**
     * @param  array<string, mixed>  $baseReport
     * @return array<string, mixed>
     */
    public function enrichReport(array $baseReport, ?int $outletId = null): array
    {
        $failedPostings = (int) ($baseReport['failedPostings'] ?? 0);
        $giftVariance = abs((float) ($baseReport['giftCardVariance'] ?? 0));
        $inventoryDiff = abs((float) ($baseReport['inventoryValuationDifference'] ?? 0));
        $payrollDiff = abs((float) ($baseReport['payrollVariance'] ?? 0));
        $procurementDiff = abs((float) ($baseReport['procurementVariance'] ?? 0));

        $postingFailuresSeverity = $this->severityEngine->postingFailuresSeverity($failedPostings);
        $giftCardSeverity = $this->severityEngine->giftCardVarianceSeverity($giftVariance);
        $inventorySeverity = $this->severityEngine->inventoryVarianceSeverity($inventoryDiff);
        $payrollSeverity = $this->severityEngine->payrollVarianceSeverity($payrollDiff);
        $procurementSeverity = $this->severityEngine->procurementVarianceSeverity($procurementDiff);

        $healthSeverity = $this->severityEngine->aggregateSeverity([
            $postingFailuresSeverity,
            $giftCardSeverity,
            $inventorySeverity,
            $payrollSeverity,
            $procurementSeverity,
        ]);

        $enriched = array_merge($baseReport, [
            'healthSeverity' => $healthSeverity,
            'postingFailuresSeverity' => $postingFailuresSeverity,
            'giftCardSeverity' => $giftCardSeverity,
            'inventorySeverity' => $inventorySeverity,
            'payrollSeverity' => $payrollSeverity,
            'procurementSeverity' => $procurementSeverity,
            'failureAgingBuckets' => $this->failureAgingBuckets($outletId),
            'topFailureSources' => $this->topFailureSources($outletId),
        ]);

        $enriched['priorityQueue'] = $this->priorityQueue($enriched);

        return $enriched;
    }

    public function maxProcurementVariance(array $procurementReconciliation): float
    {
        $max = 0.0;
        foreach (['grni', 'inventory', 'accountsPayable'] as $key) {
            if (! isset($procurementReconciliation[$key]) || ! is_array($procurementReconciliation[$key])) {
                continue;
            }
            $max = max($max, abs((float) ($procurementReconciliation[$key]['difference'] ?? 0)));
        }

        return $max;
    }
}
