<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\Accounting\Domain\AccountingHealthSnapshot;
use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Models\Modules\Payments\Domain\PaymentIncident;
use App\Models\Modules\System\Domain\BugReport;
use App\Models\Modules\System\Domain\BugReportAttachment;
use App\Models\Modules\System\Domain\BugReportComment;
use App\Models\Modules\System\Domain\FailedJobSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoMonitoringSeeder extends Seeder
{
    public function run(): void
    {
        $base = DemoSeederContext::baseTime();

        foreach (DemoSeederContext::outlets() as $outlet) {
            $manager = User::query()->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outlet->id))->first();
            $this->seedNotifications($outlet->id, $manager?->id, $base);
            $this->seedAccountingHealth($outlet->id, $base);
            $this->seedPaymentHealth($outlet->id, $base);
            $this->seedPostingFailures($outlet->id);
            $this->seedAuditLogs($outlet->id, $manager?->id, $base);
            $this->seedBugReports($outlet->id, $manager?->id);
        }

        $this->seedFailedJobs($base);
    }

    private function seedNotifications(int $outletId, ?int $userId, CarbonImmutable $base): void
    {
        if ($userId === null) {
            return;
        }

        $alerts = [
            [UserNotification::MODULE_INVENTORY, 'Critical stock: Chicken Breast', UserNotification::SEVERITY_CRITICAL],
            [UserNotification::MODULE_MENU_INTELLIGENCE, 'Menu dog item flagged', UserNotification::SEVERITY_WARNING],
            [UserNotification::MODULE_PROCUREMENT, 'PR awaiting approval', UserNotification::SEVERITY_INFO],
            [UserNotification::MODULE_ACCOUNTING, 'Posting failure detected', UserNotification::SEVERITY_WARNING],
            [UserNotification::MODULE_PAYMENTS, 'Stale QRIS payment', UserNotification::SEVERITY_CRITICAL],
            [UserNotification::MODULE_SYSTEM, 'Failed job replay pending', UserNotification::SEVERITY_WARNING],
            [UserNotification::MODULE_PAYROLL, 'Payroll run ready for approval', UserNotification::SEVERITY_INFO],
        ];

        foreach ($alerts as $index => [$module, $title, $severity]) {
            UserNotification::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'user_id' => $userId, 'source_type' => 'demo_seed', 'source_id' => (string) $index],
                [
                    'severity' => $severity,
                    'source_module' => $module,
                    'title' => $title,
                    'message' => "{$title} — demo environment",
                    'action_url' => '/notifications',
                    'read_at' => $index % 2 === 0 ? $base->addDays(1) : null,
                    'metadata' => ['demo' => true],
                ],
            );
        }
    }

    private function seedAccountingHealth(int $outletId, CarbonImmutable $base): void
    {
        for ($d = 0; $d < 14; $d++) {
            AccountingHealthSnapshot::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'snapshot_date' => $base->subDays($d)->toDateString()],
                [
                    'posting_failures' => $d < 3 ? 2 : 0,
                    'gift_card_variance' => 15000,
                    'inventory_variance' => 22000,
                    'payroll_variance' => 0,
                    'procurement_variance' => 8000,
                    'severity' => $d < 3 ? AccountingHealthSnapshot::SEVERITY_WARNING : AccountingHealthSnapshot::SEVERITY_HEALTHY,
                ],
            );
        }
    }

    private function seedPaymentHealth(int $outletId, CarbonImmutable $base): void
    {
        foreach (['midtrans', 'xendit'] as $provider) {
            PaymentHealthSnapshot::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'provider' => $provider, 'snapshot_date' => $base->toDateString()],
                [
                    'health_status' => 'degraded',
                    'payment_success_rate' => 94.5,
                    'webhook_success_rate' => 91.0,
                    'stale_payments' => 3,
                    'failed_webhooks' => 2,
                    'average_processing_time_ms' => 840,
                    'active_incidents' => 1,
                ],
            );
        }

        $incidents = [
            [
                'provider' => 'midtrans',
                'incident_type' => PaymentIncident::TYPE_WEBHOOK_SPIKE,
                'status' => PaymentIncident::STATUS_OPEN,
                'title' => 'Webhook failure spike',
                'resolved' => false,
            ],
            [
                'provider' => 'xendit',
                'incident_type' => PaymentIncident::TYPE_STALE_SPIKE,
                'status' => PaymentIncident::STATUS_RESOLVED,
                'title' => 'Stale QRIS payments',
                'resolved' => true,
            ],
            [
                'provider' => 'midtrans',
                'incident_type' => PaymentIncident::TYPE_PROVIDER_CRITICAL,
                'status' => PaymentIncident::STATUS_RESOLVED,
                'title' => 'Provider timeout (historical)',
                'resolved' => true,
            ],
        ];

        foreach ($incidents as $i => $row) {
            PaymentIncident::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'title' => $row['title']],
                [
                    'provider' => $row['provider'],
                    'incident_type' => $row['incident_type'],
                    'severity' => $row['status'] === PaymentIncident::STATUS_OPEN ? 'critical' : 'warning',
                    'description' => 'Demo payment incident for Payment Health dashboard',
                    'opened_at' => $base->subDays(5 - $i),
                    'resolved_at' => $row['resolved'] ? $base->subDays(2) : null,
                    'duration_minutes' => $row['resolved'] ? 45 : null,
                    'status' => $row['status'],
                ],
            );
        }
    }

    private function seedPostingFailures(int $outletId): void
    {
        $rows = [
            ['code' => AccountingPostingFailure::ERROR_POSTING, 'status' => AccountingPostingFailure::STATUS_PENDING],
            ['code' => AccountingPostingFailure::ERROR_MISSING_MAPPING, 'status' => AccountingPostingFailure::STATUS_RESOLVED],
            ['code' => AccountingPostingFailure::ERROR_DUPLICATE, 'status' => AccountingPostingFailure::STATUS_IGNORED],
        ];

        foreach ($rows as $i => $row) {
            AccountingPostingFailure::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'source_type' => 'demo_order', 'source_id' => $i + 1],
                [
                    'error_code' => $row['code'],
                    'error_message' => "Demo posting failure {$row['code']}",
                    'payload_json' => ['demo' => true],
                    'status' => $row['status'],
                    'resolved_at' => $row['status'] === AccountingPostingFailure::STATUS_RESOLVED ? now()->subDay() : null,
                ],
            );
        }
    }

    private function seedAuditLogs(int $outletId, ?int $actorId, CarbonImmutable $base): void
    {
        $batch = [];
        $now = now();

        for ($i = 1; $i <= 500; $i++) {
            $batch[] = [
                'outlet_id' => $outletId,
                'actor_user_id' => $actorId,
                'event_type' => $i % 5 === 0 ? 'order.paid' : 'order.created',
                'entity_type' => 'order',
                'entity_id' => 1000 + $i,
                'payload' => json_encode(['demo' => true, 'seq' => $i]),
                'occurred_at' => $base->subDays($i % 30)->addMinutes($i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($batch, 100) as $chunk) {
            PosEventLog::query()->insertOrIgnore($chunk);
        }
    }

    private function seedBugReports(int $outletId, ?int $userId): void
    {
        $statuses = [
            BugReport::STATUS_OPEN,
            BugReport::STATUS_TRIAGED,
            BugReport::STATUS_INVESTIGATING,
            BugReport::STATUS_FIXED,
            BugReport::STATUS_CLOSED,
            BugReport::STATUS_CLOSED,
            BugReport::STATUS_OPEN,
        ];

        foreach ($statuses as $i => $status) {
            $report = BugReport::query()->updateOrCreate(
                ['outlet_id' => $outletId, 'title' => "Demo bug report #{$i}"],
                [
                    'reporter_user_id' => $userId,
                    'message' => 'Reproduced in demo seed environment',
                    'severity' => BugReport::SEVERITY_MEDIUM,
                    'status' => $status,
                    'current_route' => '/pos',
                    'browser' => 'Chrome',
                    'user_agent' => 'DemoSeeder/1.0',
                    'viewport' => '1920x1080',
                    'app_version' => 'demo',
                    'diagnostics_json' => ['console' => [], 'network' => []],
                    'assigned_to_user_id' => $userId,
                    'resolved_at' => in_array($status, [BugReport::STATUS_FIXED, BugReport::STATUS_CLOSED], true) ? now()->subDay() : null,
                ],
            );

            BugReportComment::query()->updateOrCreate(
                ['bug_report_id' => $report->id, 'comment' => 'Triaged in demo seed'],
                ['user_id' => $userId, 'created_at' => now()->subHours(2)],
            );

            BugReportAttachment::query()->updateOrCreate(
                ['bug_report_id' => $report->id, 'file_path' => "demo/bug-{$report->id}.png"],
                ['file_type' => 'image/png', 'file_size' => 12000, 'created_at' => now()->subHours(3)],
            );
        }
    }

    private function seedFailedJobs(CarbonImmutable $base): void
    {
        $jobs = [
            ['class' => 'App\\Jobs\\ProcessPaymentWebhook', 'resolved' => false],
            ['class' => 'App\\Jobs\\SyncMenuCosts', 'resolved' => false],
            ['class' => 'App\\Jobs\\InventoryValuationRecalc', 'resolved' => true],
            ['class' => 'App\\Jobs\\LoyaltyAutomationDispatch', 'resolved' => true],
            ['class' => 'App\\Jobs\\PaymentReconcile', 'resolved' => false],
            ['class' => 'App\\Jobs\\PrintJobDispatch', 'resolved' => true],
            ['class' => 'App\\Jobs\\TerminalSyncReplay', 'resolved' => false],
        ];

        if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            foreach ($jobs as $i => $job) {
                DB::table('failed_jobs')->updateOrInsert(
                    ['uuid' => sprintf('demo-failed-job-%02d', $i + 1)],
                    [
                        'connection' => 'database',
                        'queue' => 'default',
                        'payload' => json_encode(['displayName' => $job['class']]),
                        'exception' => 'Demo failed job exception',
                        'failed_at' => $base->subDays($i)->toDateTimeString(),
                    ],
                );
            }
        }

        FailedJobSnapshot::query()->updateOrCreate(
            ['snapshot_date' => $base->toDateString()],
            [
                'total_failures' => 7,
                'critical_failures' => 2,
                'resolved_failures' => 3,
                'health_status' => 'warning',
            ],
        );
    }
}
