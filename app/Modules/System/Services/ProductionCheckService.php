<?php

namespace App\Modules\System\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class ProductionCheckService
{
    private const SCHEDULER_HEARTBEAT_KEY = 'system:scheduler:last_run_at';

    private const QUEUE_HEARTBEAT_KEY = 'system:queue:last_heartbeat_at';

    /** @var list<array<string, mixed>> */
    private array $checks = [];

    /**
     * @return array{status: string, checks: list<array<string, mixed>>, summary: array{pass: int, warn: int, fail: int}}
     */
    public function run(bool $strictProduction = true): array
    {
        $this->checks = [];

        $this->checkApplication($strictProduction);
        $this->checkDatabase();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkStorage();

        $summary = $this->summarize();
        $status = $this->resolveOverallStatus($summary);

        return [
            'status' => $status,
            'checks' => $this->checks,
            'summary' => $summary,
        ];
    }

    public static function recordSchedulerHeartbeat(): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(2));
    }

    public static function recordQueueHeartbeat(): void
    {
        Cache::put(self::QUEUE_HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(2));
    }

    private function checkApplication(bool $strictProduction): void
    {
        $env = (string) config('app.env', 'local');
        $isProduction = $env === 'production';

        $this->addCheck(
            'application.env',
            'application',
            $isProduction ? 'pass' : ($strictProduction ? 'fail' : 'warn'),
            $isProduction ? 'APP_ENV is production' : sprintf('APP_ENV is "%s" (expected production)', $env),
            ['appEnv' => $env],
        );

        $debug = (bool) config('app.debug', true);
        $this->addCheck(
            'application.debug',
            'application',
            $debug ? 'fail' : 'pass',
            $debug ? 'APP_DEBUG must be false in production' : 'APP_DEBUG is disabled',
            ['appDebug' => $debug],
        );

        $appKey = (string) config('app.key', '');
        $this->addCheck(
            'application.key',
            'application',
            $appKey !== '' ? 'pass' : 'fail',
            $appKey !== '' ? 'APP_KEY is configured' : 'APP_KEY is missing',
        );
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            $this->addCheck('database.connection', 'database', 'pass', 'Database connection successful');
        } catch (\Throwable $e) {
            $this->addCheck(
                'database.connection',
                'database',
                'fail',
                'Database connection failed: '.$e->getMessage(),
            );

            return;
        }

        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $ran = $migrator->getRepository()->getRan();
            $pending = array_values(array_diff(array_keys($files), $ran));
            $pendingCount = count($pending);

            $this->addCheck(
                'database.migrations',
                'database',
                $pendingCount === 0 ? 'pass' : 'fail',
                $pendingCount === 0
                    ? 'All migrations applied'
                    : sprintf('%d pending migration(s)', $pendingCount),
                ['pending' => $pending, 'pendingCount' => $pendingCount],
            );
        } catch (\Throwable $e) {
            $this->addCheck(
                'database.migrations',
                'database',
                'fail',
                'Unable to verify migration status: '.$e->getMessage(),
            );
        }
    }

    private function checkQueue(): void
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === 'sync') {
            $this->addCheck(
                'queue.driver',
                'queue',
                'warn',
                'Queue driver is sync — background jobs run inline (not recommended for production)',
                ['driver' => $driver],
            );

            return;
        }

        $failedCount = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedCount = (int) DB::table('failed_jobs')->count();
        }

        $this->addCheck(
            'queue.failed_jobs',
            'queue',
            $failedCount === 0 ? 'pass' : ($failedCount <= 5 ? 'warn' : 'fail'),
            $failedCount === 0
                ? 'No failed queue jobs'
                : sprintf('%d failed queue job(s)', $failedCount),
            ['failedJobs' => $failedCount],
        );

        $workerActive = $this->isQueueWorkerActive();
        $this->addCheck(
            'queue.worker',
            'queue',
            $workerActive ? 'pass' : 'warn',
            $workerActive
                ? 'Queue worker heartbeat detected'
                : 'No recent queue worker heartbeat — verify supervisor is running',
            ['driver' => $driver],
        );

        if (Schema::hasTable('jobs')) {
            $pendingJobs = (int) DB::table('jobs')->count();
            $this->addCheck(
                'queue.pending_jobs',
                'queue',
                $pendingJobs < 100 ? 'pass' : ($pendingJobs < 500 ? 'warn' : 'fail'),
                sprintf('%d pending job(s) in queue', $pendingJobs),
                ['pendingJobs' => $pendingJobs],
            );
        }
    }

    private function checkScheduler(): void
    {
        $lastRun = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        if (! is_string($lastRun) || $lastRun === '') {
            $this->addCheck(
                'scheduler.last_run',
                'scheduler',
                'warn',
                'Scheduler heartbeat not found — ensure cron runs schedule:run every minute',
            );

            return;
        }

        $ageMinutes = (int) now()->diffInMinutes(\Carbon\Carbon::parse($lastRun), true);
        $this->addCheck(
            'scheduler.last_run',
            'scheduler',
            $ageMinutes <= 5 ? 'pass' : ($ageMinutes <= 15 ? 'warn' : 'fail'),
            sprintf('Last scheduler run %d minute(s) ago', $ageMinutes),
            ['lastRunAt' => $lastRun, 'ageMinutes' => $ageMinutes],
        );
    }

    private function checkStorage(): void
    {
        $paths = [
            'storage.framework' => storage_path('framework'),
            'storage.logs' => storage_path('logs'),
            'storage.app' => storage_path('app'),
            'bootstrap.cache' => base_path('bootstrap/cache'),
        ];

        foreach ($paths as $id => $path) {
            $writable = File::isDirectory($path) && is_writable($path);
            $this->addCheck(
                'storage.'.str_replace('.', '_', $id),
                'storage',
                $writable ? 'pass' : 'fail',
                $writable ? basename($path).' is writable' : basename($path).' is not writable',
                ['path' => $path],
            );
        }
    }

    private function isQueueWorkerActive(): bool
    {
        $heartbeat = Cache::get(self::QUEUE_HEARTBEAT_KEY);
        if (is_string($heartbeat) && $heartbeat !== '') {
            return now()->diffInMinutes(\Carbon\Carbon::parse($heartbeat), true) <= 10;
        }

        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '>=', now()->subMinutes(10)->timestamp)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function addCheck(
        string $id,
        string $category,
        string $status,
        string $message,
        ?array $details = null,
    ): void {
        $this->checks[] = array_filter([
            'id' => $id,
            'category' => $category,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{pass: int, warn: int, fail: int}
     */
    private function summarize(): array
    {
        $pass = 0;
        $warn = 0;
        $fail = 0;

        foreach ($this->checks as $check) {
            match ($check['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                default => $fail++,
            };
        }

        return ['pass' => $pass, 'warn' => $warn, 'fail' => $fail];
    }

    /**
     * @param  array{pass: int, warn: int, fail: int}  $summary
     */
    private function resolveOverallStatus(array $summary): string
    {
        if ($summary['fail'] > 0) {
            return 'critical';
        }

        if ($summary['warn'] > 0) {
            return 'degraded';
        }

        return 'healthy';
    }
}
