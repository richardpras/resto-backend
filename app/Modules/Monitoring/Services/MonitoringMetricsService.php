<?php

namespace App\Modules\Monitoring\Services;

use App\Models\User;
use App\Modules\Loyalty\Services\CustomerAnalyticsService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitoringMetricsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly CustomerAnalyticsService $customerAnalyticsService,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function aggregate(User $user, array $filters): array
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        $requestedOutletId = isset($filters['outletId']) ? (int) $filters['outletId'] : null;

        if ($requestedOutletId !== null && ! in_array($requestedOutletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }

        $scopedOutletIds = $requestedOutletId !== null ? [$requestedOutletId] : $allowedOutletIds;
        return $this->aggregateForOutletIds($scopedOutletIds, $filters, $requestedOutletId);
    }

    /**
     * @param list<int> $scopedOutletIds
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function aggregateForOutletIds(array $scopedOutletIds, array $filters = [], ?int $requestedOutletId = null): array
    {
        $dateFrom = $this->parseDate($filters['dateFrom'] ?? null, false);
        $dateTo = $this->parseDate($filters['dateTo'] ?? null, true);

        return [
            'outletScope' => [
                'requestedOutletId' => $requestedOutletId,
                'allowedOutletIds' => $scopedOutletIds,
            ],
            'window' => [
                'dateFrom' => $dateFrom?->toIso8601String(),
                'dateTo' => $dateTo?->toIso8601String(),
            ],
            'activePosSessions' => $this->activePosSessions($scopedOutletIds, $dateFrom, $dateTo),
            'pendingKitchenTickets' => $this->pendingKitchenTickets($scopedOutletIds, $dateFrom, $dateTo),
            'paymentRate' => $this->paymentRate($scopedOutletIds, $dateFrom, $dateTo),
            'stalePayments' => $this->stalePayments($scopedOutletIds, $dateFrom, $dateTo),
            'qrQueue' => $this->qrQueue($scopedOutletIds, $dateFrom, $dateTo),
            'printerQueue' => $this->printerQueue($scopedOutletIds, $dateFrom, $dateTo),
            'reconciliationFailures' => $this->reconciliationFailures($scopedOutletIds, $dateFrom, $dateTo),
            'asyncRecoveryFailures' => $this->asyncRecoveryFailures($scopedOutletIds, $dateFrom, $dateTo),
            'offlineResilience' => $this->offlineResilienceMetrics($scopedOutletIds, $dateFrom, $dateTo),
            'hardwareBridge' => $this->hardwareBridgeMetrics($scopedOutletIds, $dateFrom, $dateTo),
            'crmMetrics' => $this->customerAnalyticsService->metricsForOutlets($scopedOutletIds),
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function activePosSessions(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $query = DB::table('pos_sessions')
            ->whereIn('outlet_id', $outletIds)
            ->where('status', 'open');

        $this->applyDateRange($query, 'opened_at', $dateFrom, $dateTo);

        return ['count' => (int) $query->count()];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function pendingKitchenTickets(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $query = DB::table('kitchen_tickets')
            ->whereIn('outlet_id', $outletIds)
            ->whereIn('status', ['queued', 'in_progress', 'ready']);

        $this->applyDateRange($query, 'queued_at', $dateFrom, $dateTo);

        return ['count' => (int) $query->count()];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function paymentRate(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $base = DB::table('payment_transactions')
            ->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($base, 'created_at', $dateFrom, $dateTo);

        $paidCount = (int) (clone $base)->where('status', 'paid')->count();
        $failureCount = (int) (clone $base)->whereIn('status', ['failed', 'expired', 'cancelled'])->count();
        $denominator = $paidCount + $failureCount;

        return [
            'paidCount' => $paidCount,
            'failureCount' => $failureCount,
            'successRate' => $denominator > 0 ? round(($paidCount / $denominator) * 100, 2) : 0.0,
            'failureRate' => $denominator > 0 ? round(($failureCount / $denominator) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function stalePayments(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $thresholdMinutes = max(1, (int) config('payments.recovery.stale_pending_minutes', 15));
        $threshold = now()->subMinutes($thresholdMinutes);

        $query = DB::table('payment_transactions')
            ->whereIn('outlet_id', $outletIds)
            ->whereIn('status', ['pending', 'authorized'])
            ->where('created_at', '<=', $threshold);

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);

        return [
            'count' => (int) $query->count(),
            'thresholdMinutes' => $thresholdMinutes,
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function qrQueue(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $base = DB::table('qr_order_requests')
            ->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($base, 'created_at', $dateFrom, $dateTo);

        return [
            'pendingConfirmation' => (int) (clone $base)->where('status', 'pending_cashier_confirmation')->count(),
            'expired' => (int) (clone $base)->where('status', 'expired')->count(),
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function printerQueue(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $base = DB::table('print_jobs')
            ->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($base, 'created_at', $dateFrom, $dateTo);

        return [
            'pending' => (int) (clone $base)->where('status', 'pending')->count(),
            'failed' => (int) (clone $base)->where('status', 'failed')->count(),
            'recoverable' => (int) (clone $base)->where('recovery_state', 'recoverable')->count(),
            'deadLetter' => (int) (clone $base)->where('recovery_state', 'dead_letter')->count(),
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function reconciliationFailures(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $query = DB::table('payment_webhook_receipts as r')
            ->join('payment_transactions as t', function ($join): void {
                $join->on('t.provider', '=', 'r.provider')
                    ->on('t.external_reference', '=', 'r.external_reference');
            })
            ->whereIn('t.outlet_id', $outletIds)
            ->whereNotNull('r.last_error')
            ->whereNull('r.processed_at');

        $this->applyDateRange($query, 'r.created_at', $dateFrom, $dateTo);

        return ['count' => (int) $query->distinct('r.id')->count('r.id')];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function asyncRecoveryFailures(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        $query = DB::table('payment_transactions')
            ->whereIn('outlet_id', $outletIds)
            ->whereNotNull('last_async_error');

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);

        return [
            'count' => (int) $query->count(),
            'queuedForRetry' => (int) (clone $query)->whereNotNull('async_retry_after')->count(),
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function offlineResilienceMetrics(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        if ($outletIds === []) {
            return [
                'registeredTerminals' => 0,
                'staleTerminalDevices' => 0,
                'aggregateReconnectCounter' => 0,
                'syncOperationsApplied' => 0,
                'syncReplayFailures' => 0,
                'syncStaleReplayRejections' => 0,
                'syncConflictOperations' => 0,
                'duplicateReplayAttemptsObserved' => 0,
                'conflictEventsLogged' => 0,
            ];
        }

        $syncBase = DB::table('terminal_sync_operations')->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($syncBase, 'created_at', $dateFrom, $dateTo);

        $duplicateBase = DB::table('terminal_sync_operations')->whereIn('outlet_id', $outletIds)->where('duplicate_replay_hits', '>', 0);
        $this->applyDateRange($duplicateBase, 'updated_at', $dateFrom, $dateTo);

        $conflictBase = DB::table('terminal_sync_conflict_events')->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($conflictBase, 'created_at', $dateFrom, $dateTo);

        $minutes = max(1, (int) config('terminals.stale_after_minutes', 120));
        $threshold = now()->copy()->subMinutes($minutes);

        $staleTerminalsQuery = DB::table('terminal_devices')
            ->whereIn('outlet_id', $outletIds)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(function ($builder) use ($threshold): void {
                $builder
                    ->where(function ($nested) use ($threshold): void {
                        $nested->whereNull('last_seen_at')->where('created_at', '<', $threshold);
                    })
                    ->orWhere(function ($nested) use ($threshold): void {
                        $nested->whereNotNull('last_seen_at')->where('last_seen_at', '<', $threshold);
                    });
            });

        return [
            'registeredTerminals' => (int) DB::table('terminal_devices')
                ->whereIn('outlet_id', $outletIds)
                ->whereNull('revoked_at')
                ->count(),
            'staleTerminalDevices' => (int) $staleTerminalsQuery->count(),
            'aggregateReconnectCounter' => (int) DB::table('terminal_devices')
                ->whereIn('outlet_id', $outletIds)
                ->sum('reconnect_count'),
            'syncOperationsApplied' => (int) (clone $syncBase)->where('status', 'applied')->count(),
            'syncReplayFailures' => (int) (clone $syncBase)->where('status', 'failed')->count(),
            'syncStaleReplayRejections' => (int) (clone $syncBase)->where('status', 'rejected_stale')->count(),
            'syncConflictOperations' => (int) (clone $syncBase)->where('status', 'conflict')->count(),
            'duplicateReplayAttemptsObserved' => (int) $duplicateBase->sum('duplicate_replay_hits'),
            'conflictEventsLogged' => (int) $conflictBase->count(),
        ];
    }

    /**
     * @param  list<int>  $outletIds
     */
    private function hardwareBridgeMetrics(array $outletIds, ?Carbon $dateFrom, ?Carbon $dateTo): array
    {
        if ($outletIds === []) {
            return [
                'activeBridges' => 0,
                'staleBridges' => 0,
                'reconnectCount' => 0,
                'queueDepth' => 0,
                'deadLetterCount' => 0,
                'ackLatencyMs' => 0,
                'retryCount' => 0,
                'crashCount' => 0,
                'restartCount' => 0,
                'watchdogDegradedCount' => 0,
                'stalledSpoolCount' => 0,
                'deploymentHeadlessCount' => 0,
                'serviceModeWindowsCount' => 0,
                'serviceModeSystemdCount' => 0,
                'updateAvailableCount' => 0,
            ];
        }

        $staleMinutes = max(1, (int) config('hardware.session_stale_after_minutes', 15));
        $staleThreshold = now()->subMinutes($staleMinutes);

        $deviceBase = DB::table('hardware_bridge_devices')
            ->whereIn('outlet_id', $outletIds)
            ->whereNull('revoked_at')
            ->whereNull('disabled_at');

        $commandBase = DB::table('hardware_command_logs')->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($commandBase, 'created_at', $dateFrom, $dateTo);

        $ackLatencyMs = (int) round((float) (clone $commandBase)
            ->whereNotNull('acked_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MICROSECOND, created_at, acked_at) / 1000) as avg_ack_ms')
            ->value('avg_ack_ms') ?? 0);
        $deviceEvents = DB::table('hardware_device_events')->whereIn('outlet_id', $outletIds);
        $this->applyDateRange($deviceEvents, 'occurred_at', $dateFrom, $dateTo);
        $watchdogEvents = (clone $deviceEvents)->whereIn('event_type', ['heartbeat_received', 'device_disabled', 'device_revoked']);

        return [
            'activeBridges' => (int) (clone $deviceBase)->where('status', 'active')->count(),
            'staleBridges' => (int) (clone $deviceBase)
                ->where(function ($builder) use ($staleThreshold): void {
                    $builder
                        ->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<=', $staleThreshold);
                })
                ->count(),
            'reconnectCount' => (int) (clone $deviceBase)->sum('reconnect_count'),
            'queueDepth' => (int) (clone $commandBase)->whereIn('status', ['pending', 'processing', 'replay_pending', 'queued'])->count(),
            'deadLetterCount' => (int) (clone $commandBase)->where('status', 'dead_letter')->count(),
            'ackLatencyMs' => max(0, $ackLatencyMs),
            'retryCount' => (int) (clone $commandBase)->sum('retry_count'),
            'crashCount' => (int) (clone $deviceBase)->selectRaw("COALESCE(SUM(JSON_EXTRACT(metadata, '$.watchdog.crashCount')), 0) as aggregate")->value('aggregate'),
            'restartCount' => (int) (clone $deviceBase)->selectRaw("COALESCE(SUM(JSON_EXTRACT(metadata, '$.watchdog.restartCount')), 0) as aggregate")->value('aggregate'),
            'watchdogDegradedCount' => (int) (clone $watchdogEvents)->whereRaw("JSON_EXTRACT(payload, '$.runtimeState') IN ('\"degraded\"', '\"stale\"')")->count(),
            'stalledSpoolCount' => (int) (clone $deviceBase)->whereRaw("JSON_EXTRACT(metadata, '$.watchdog.stalledSpoolDetected') = true")->count(),
            'deploymentHeadlessCount' => (int) (clone $deviceBase)->whereRaw("JSON_EXTRACT(metadata, '$.deployment.headless') = true")->count(),
            'serviceModeWindowsCount' => (int) (clone $deviceBase)->whereRaw("JSON_EXTRACT(metadata, '$.deployment.serviceMode') = '\"windows-service\"'")->count(),
            'serviceModeSystemdCount' => (int) (clone $deviceBase)->whereRaw("JSON_EXTRACT(metadata, '$.deployment.serviceMode') = '\"systemd\"'")->count(),
            'updateAvailableCount' => (int) (clone $deviceBase)->whereRaw("JSON_EXTRACT(metadata, '$.updates.available') = true")->count(),
        ];
    }

    private function parseDate(mixed $value, bool $endOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = Carbon::parse($value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function applyDateRange(object $query, string $column, ?Carbon $dateFrom, ?Carbon $dateTo): void
    {
        if ($dateFrom !== null) {
            $query->where($column, '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where($column, '<=', $dateTo);
        }
    }
}
