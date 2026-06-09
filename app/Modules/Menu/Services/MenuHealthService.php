<?php

namespace App\Modules\Menu\Services;

use App\Modules\Menu\Jobs\CreateDailyAnalyticsSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyAutomationSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyEngineeringSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyForecastSnapshotJob;
use App\Modules\Menu\Jobs\CreateDailyOptimizationSnapshotJob;
use App\Models\Modules\Menu\Domain\AutomationSnapshot;
use App\Models\Modules\Menu\Domain\ForecastSnapshot;
use App\Models\Modules\Menu\Domain\MenuAnalyticsSnapshot;
use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\Modules\Menu\Domain\MenuOptimizationSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MenuHealthService
{
    private const WARNING_HOURS = 48;

    private const CRITICAL_HOURS = 168;

    /** @var array<int, string> */
    private const DAILY_JOBS = [
        CreateDailyAnalyticsSnapshotJob::class,
        CreateDailyEngineeringSnapshotJob::class,
        CreateDailyOptimizationSnapshotJob::class,
        CreateDailyForecastSnapshotJob::class,
        CreateDailyAutomationSnapshotJob::class,
    ];

    public function __construct(
        private readonly MenuIntelligenceCacheService $cacheService,
        private readonly MenuHardeningAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function getSystemHealth(int $outletId, ?User $actor = null): array
    {
        $analytics = $this->evaluateSnapshotDomain(
            MenuAnalyticsSnapshot::query()->where('outlet_id', $outletId),
            'analytics',
        );
        $engineering = $this->evaluateSnapshotDomain(
            MenuEngineeringSnapshot::query()->where('outlet_id', $outletId),
            'engineering',
        );
        $optimization = $this->evaluateSnapshotDomain(
            MenuOptimizationSnapshot::query()->where('outlet_id', $outletId),
            'optimization',
        );
        $automation = $this->evaluateSnapshotDomain(
            AutomationSnapshot::query()->where('outlet_id', $outletId),
            'automation',
        );
        $forecasting = $this->evaluateSnapshotDomain(
            ForecastSnapshot::query()->where('outlet_id', $outletId),
            'forecasting',
        );

        $cache = $this->evaluateCache($outletId);
        $failedJobs = $this->detectFailedJobs();

        $score = 100.0;
        $issues = [];

        foreach ([$analytics, $engineering, $optimization, $automation, $forecasting] as $domain) {
            if ($domain['status'] === 'missing') {
                $score -= 20;
                $issues[] = "Missing {$domain['domain']} snapshot";
            } elseif ($domain['status'] === 'critical') {
                $score -= 20;
                $issues[] = "{$domain['domain']} snapshot older than 7 days";
            } elseif ($domain['status'] === 'warning') {
                $score -= 10;
                $issues[] = "{$domain['domain']} snapshot older than 48 hours";
            }
        }

        if ($failedJobs['count'] > 0) {
            $score -= 15 * $failedJobs['count'];
            $issues[] = "{$failedJobs['count']} failed queue job(s) detected";
        }

        foreach ($cache as $key => $present) {
            if (! $present) {
                $score -= 2;
                $issues[] = "Missing cache: {$key}";
            }
        }

        $score = max(0.0, min(100.0, round($score, 2)));
        $status = $this->statusBand($score);

        $result = [
            'outletId' => $outletId,
            'score' => $score,
            'status' => $status,
            'analytics' => $analytics,
            'engineering' => $engineering,
            'optimization' => $optimization,
            'automation' => $automation,
            'forecasting' => $forecasting,
            'cache' => $cache,
            'failedJobs' => $failedJobs,
            'issues' => $issues,
        ];

        $this->auditService->log('health_check_generated', $outletId, $outletId, $actor, [
            'score' => $score,
            'status' => $status,
            'issueCount' => count($issues),
        ], entityType: 'outlet');

        return $result;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query */
    private function evaluateSnapshotDomain($query, string $domain): array
    {
        $latest = $query->orderByDesc('created_at')->first();

        if ($latest === null) {
            return [
                'domain' => $domain,
                'status' => 'missing',
                'latestSnapshotAt' => null,
                'ageHours' => null,
            ];
        }

        $createdAt = Carbon::parse($latest->created_at);
        $ageHours = round($createdAt->diffInMinutes(now()) / 60, 2);

        $status = 'ok';
        if ($ageHours > self::CRITICAL_HOURS) {
            $status = 'critical';
        } elseif ($ageHours > self::WARNING_HOURS) {
            $status = 'warning';
        }

        return [
            'domain' => $domain,
            'status' => $status,
            'latestSnapshotAt' => $createdAt->toIso8601String(),
            'ageHours' => $ageHours,
        ];
    }

    /** @return array<string,bool> */
    private function evaluateCache(int $outletId): array
    {
        return [
            'dashboard' => $this->cacheService->has($outletId, MenuIntelligenceCacheService::PREFIX_DASHBOARD),
            'forecast' => $this->cacheService->has($outletId, MenuIntelligenceCacheService::PREFIX_FORECAST),
            'optimization' => $this->cacheService->has($outletId, MenuIntelligenceCacheService::PREFIX_OPTIMIZATION),
            'engineering' => $this->cacheService->has(
                $outletId,
                MenuIntelligenceCacheService::PREFIX_ENGINEERING,
                $this->defaultEngineeringSuffix(),
            ),
            'automation' => $this->cacheService->has($outletId, MenuIntelligenceCacheService::PREFIX_AUTOMATION),
        ];
    }

    private function defaultEngineeringSuffix(): string
    {
        return md5('|');
    }

    /** @return array<string,mixed> */
    private function detectFailedJobs(): array
    {
        if (! $this->failedJobsTableExists()) {
            return ['count' => 0, 'jobs' => []];
        }

        $jobs = [];
        foreach (self::DAILY_JOBS as $class) {
            $short = class_basename($class);
            $count = DB::table('failed_jobs')
                ->where('payload', 'like', '%'.$short.'%')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
            if ($count > 0) {
                $jobs[] = ['job' => $short, 'count' => $count];
            }
        }

        return [
            'count' => array_sum(array_column($jobs, 'count')),
            'jobs' => $jobs,
        ];
    }

    private function failedJobsTableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('failed_jobs');
    }

    private function statusBand(float $score): string
    {
        if ($score >= 85) {
            return 'excellent';
        }
        if ($score >= 70) {
            return 'good';
        }
        if ($score >= 50) {
            return 'warning';
        }

        return 'critical';
    }
}
