<?php

namespace App\Modules\System\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FailedJobMonitoringService
{
    public function __construct(
        private readonly FailedJobSeverityEngine $severityEngine,
    ) {}

    public function tableExists(): bool
    {
        return DB::getSchemaBuilder()->hasTable('failed_jobs');
    }

    /**
     * @return array{
     *     failedJobs: int,
     *     criticalFailures: int,
     *     repeatFailures: int,
     *     oldestFailureMinutes: int|null,
     *     healthStatus: string,
     *     healthScore: int
     * }
     */
    public function aggregate(?Carbon $from = null, ?Carbon $to = null): array
    {
        if (! $this->tableExists()) {
            return $this->emptySummary();
        }

        $rows = $this->baseQuery($from, $to)->get();
        $parsed = $rows->map(fn ($row): array => $this->parseRow($row));

        $failedJobs = $parsed->count();
        $criticalFailures = $parsed->filter(
            fn (array $row): bool => $row['jobSeverity'] === FailedJobSeverityEngine::JOB_TIER_CRITICAL,
        )->count();

        $repeatFailures = $parsed
            ->groupBy('jobClass')
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->count();

        $oldest = $parsed->min('failedAt');
        $oldestMinutes = $oldest instanceof Carbon
            ? (int) $oldest->diffInMinutes(now())
            : null;

        $summary = [
            'failedJobs' => $failedJobs,
            'criticalFailures' => $criticalFailures,
            'repeatFailures' => $repeatFailures,
            'oldestFailureMinutes' => $oldestMinutes,
        ];

        $healthStatus = $this->severityEngine->aggregateHealth($summary);

        return array_merge($summary, [
            'healthStatus' => $healthStatus,
            'healthScore' => $this->severityEngine->healthScore($healthStatus),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listFailures(array $filters = []): LengthAwarePaginator
    {
        if (! $this->tableExists()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        }

        $query = $this->baseQuery(
            ! empty($filters['dateFrom']) ? Carbon::parse((string) $filters['dateFrom'])->startOfDay() : null,
            ! empty($filters['dateTo']) ? Carbon::parse((string) $filters['dateTo'])->endOfDay() : null,
        );

        if (! empty($filters['queue']) && is_string($filters['queue'])) {
            $query->where('queue', $filters['queue']);
        }

        $perPage = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $query->orderByDesc('failed_at')->paginate($perPage, ['*'], 'page', $page);

        $moduleFilter = isset($filters['module']) && is_string($filters['module']) ? $filters['module'] : null;
        $severityFilter = isset($filters['severity']) && is_string($filters['severity']) ? $filters['severity'] : null;

        $items = collect($paginator->items())
            ->map(fn ($row): array => $this->parseRow($row))
            ->when($moduleFilter, fn (Collection $c) => $c->filter(fn (array $r): bool => $r['module'] === $moduleFilter))
            ->when($severityFilter, fn (Collection $c) => $c->filter(fn (array $r): bool => $r['jobSeverity'] === $severityFilter))
            ->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items->all(),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
        );
    }

    /**
     * @return Collection<int, array{module: string, count: int, criticalCount: int}>
     */
    public function groupByModule(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        if (! $this->tableExists()) {
            return collect();
        }

        return $this->baseQuery($from, $to)
            ->get()
            ->map(fn ($row): array => $this->parseRow($row))
            ->groupBy('module')
            ->map(function (Collection $group, string $module): array {
                return [
                    'module' => $module,
                    'count' => $group->count(),
                    'criticalCount' => $group->where('jobSeverity', FailedJobSeverityEngine::JOB_TIER_CRITICAL)->count(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{queue: string, count: int}>
     */
    public function groupByQueue(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        if (! $this->tableExists()) {
            return collect();
        }

        return $this->baseQuery($from, $to)
            ->get()
            ->groupBy('queue')
            ->map(fn (Collection $group, string $queue): array => [
                'queue' => $queue,
                'count' => $group->count(),
            ])
            ->values();
    }

    public function extractOutletIdFromPayload(string $payload): ?int
    {
        if (preg_match('/"outletId";i:(\d+)/', $payload, $matches) === 1) {
            $id = (int) $matches[1];

            return $id > 0 ? $id : null;
        }

        if (preg_match('/"outlet_id";i:(\d+)/', $payload, $matches) === 1) {
            $id = (int) $matches[1];

            return $id > 0 ? $id : null;
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $id = (int) ($decoded['outletId'] ?? $decoded['outlet_id'] ?? 0);

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseRow(object $row): array
    {
        $payload = (string) ($row->payload ?? '');
        $jobClass = $this->extractJobClass($payload);
        $jobSeverity = $this->severityEngine->classifyJobClass($jobClass);
        $failedAt = Carbon::parse((string) $row->failed_at);

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'jobClass' => $jobClass,
            'module' => $this->severityEngine->moduleFromJobClass($jobClass),
            'jobSeverity' => $jobSeverity,
            'exceptionPreview' => $this->exceptionPreview((string) ($row->exception ?? '')),
            'failedAt' => $failedAt->toIso8601String(),
            'ageMinutes' => (int) $failedAt->diffInMinutes(now()),
            'outletId' => $this->extractOutletIdFromPayload($payload),
        ];
    }

    private function baseQuery(?Carbon $from, ?Carbon $to)
    {
        $query = DB::table('failed_jobs');

        if ($from !== null) {
            $query->where('failed_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('failed_at', '<=', $to);
        }

        return $query;
    }

    private function extractJobClass(string $payload): string
    {
        if (preg_match('/"displayName":"([^"]+)"/', $payload, $matches) === 1) {
            return class_basename(str_replace('\\\\', '\\', $matches[1]));
        }

        if (preg_match('/"job":"([^"]+)"/', $payload, $matches) === 1) {
            return class_basename(str_replace('\\\\', '\\', $matches[1]));
        }

        return 'UnknownJob';
    }

    private function exceptionPreview(string $exception): string
    {
        $firstLine = Str::before($exception, "\n");

        return Str::limit(trim($firstLine), 200);
    }

    /**
     * @return array{
     *     failedJobs: int,
     *     criticalFailures: int,
     *     repeatFailures: int,
     *     oldestFailureMinutes: int|null,
     *     healthStatus: string,
     *     healthScore: int
     * }
     */
    private function emptySummary(): array
    {
        return [
            'failedJobs' => 0,
            'criticalFailures' => 0,
            'repeatFailures' => 0,
            'oldestFailureMinutes' => null,
            'healthStatus' => FailedJobSeverityEngine::TIER_HEALTHY,
            'healthScore' => 100,
        ];
    }
}
