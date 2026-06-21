<?php

use App\Modules\System\Services\ProductionCheckService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:recover-stuck --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('payments-recover-stuck');

Schedule::command('payments:retry-async-failures --limit=50')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('payments-retry-async-failures');

Schedule::command('payments:replay-webhook-receipts --limit=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('payments-replay-webhook-receipts');

Schedule::command('payments:recover-expired --limit=100')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->name('payments-recover-expired');

Schedule::command('pos:check-stale-sessions')
    ->hourly()
    ->withoutOverlapping()
    ->name('pos-check-stale-sessions');

Schedule::command('reservations:apply-auto-no-show')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('reservations-apply-auto-no-show');

Schedule::command('loyalty:process-period-rewards')
    ->daily()
    ->withoutOverlapping()
    ->name('loyalty-process-period-rewards');

Schedule::command('loyalty:process-expiry')
    ->daily()
    ->withoutOverlapping()
    ->name('loyalty-process-expiry');

Schedule::command('notifications:sync-staff')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('notifications-sync-staff');

Schedule::command('loyalty:process-campaigns')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('loyalty-process-campaigns');

Schedule::command('loyalty:process-automations')
    ->daily()
    ->withoutOverlapping()
    ->name('loyalty-process-automations');

Schedule::command('attendance:generate-summaries')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->name('attendance-generate-summaries');

Schedule::command('accounting:health-snapshot')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('accounting-health-snapshot');

Schedule::command('payment:health-snapshot')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->name('payment-health-snapshot');

Schedule::command('payment:incident-check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('payment-incident-check');

Schedule::command('failed-jobs:snapshot')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->name('failed-jobs-snapshot');

Schedule::command('failed-jobs:monitor')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('failed-jobs-monitor');

Schedule::command('qr-orders:expire-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('qr-orders-expire-pending');

Schedule::command('print:process-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('print-process-pending');

Schedule::call(static function (): void {
    ProductionCheckService::recordSchedulerHeartbeat();
})->everyMinute()->name('system-scheduler-heartbeat');
