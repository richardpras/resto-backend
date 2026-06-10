<?php

namespace App\Modules\System\Listeners;

use App\Modules\Notifications\Services\Adapters\FailedJobNotificationAdapter;
use App\Modules\System\Services\FailedJobMonitoringService;
use App\Modules\System\Services\FailedJobSeverityEngine;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;

final class QueueJobFailedNotificationListener
{
    public function __construct(
        private readonly FailedJobMonitoringService $monitoringService,
        private readonly FailedJobSeverityEngine $severityEngine,
        private readonly FailedJobNotificationAdapter $notificationAdapter,
    ) {}

    public function handle(JobFailed $event): void
    {
        $payload = json_encode($event->job->payload(), JSON_UNESCAPED_UNICODE) ?: '';
        $jobClass = class_basename($event->job->resolveName());
        $jobSeverity = $this->severityEngine->classifyJobClass($jobClass);
        $outletId = $this->monitoringService->extractOutletIdFromPayload($payload)
            ?? $this->resolveFallbackOutletId();

        if ($outletId < 1) {
            return;
        }

        $uuid = (string) ($event->job->uuid() ?? md5($payload.$event->job->getJobId()));

        $this->notificationAdapter->notifyJobFailed($outletId, $jobClass, $jobSeverity, $uuid);
    }

    private function resolveFallbackOutletId(): int
    {
        $id = DB::table('outlets')->where('status', 'active')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : 0;
    }
}
