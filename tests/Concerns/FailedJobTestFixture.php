<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait FailedJobTestFixture
{
    protected function createFailedJobOutlet(string $prefix = 'FJ'): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet',
            'code' => $prefix.'-'.Str::upper(Str::random(6)),
            'status' => 'active',
        ]);
    }

    protected function seedFailedJob(
        string $jobClass,
        string $queue = 'default',
        ?int $outletId = null,
        ?\DateTimeInterface $failedAt = null,
    ): string {
        $uuid = (string) Str::uuid();
        $displayName = str_contains($jobClass, '\\') ? $jobClass : 'App\\Jobs\\'.$jobClass;

        $payload = [
            'uuid' => $uuid,
            'displayName' => $displayName,
            'job' => $displayName,
        ];

        if ($outletId !== null && $outletId > 0) {
            $payload['outletId'] = $outletId;
        }

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode($payload),
            'exception' => 'Exception: simulated failure in '.$jobClass,
            'failed_at' => $failedAt ?? now(),
        ]);

        return $uuid;
    }
}
