<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Models\Modules\Payments\Domain\PaymentIncident;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\Adapters\PaymentNotificationAdapter;
use App\Modules\Payments\Registry\PaymentGatewayRegistry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class PaymentHealthSnapshotService
{
    public function __construct(
        private readonly PaymentHealthService $paymentHealthService,
        private readonly PaymentHealthTelemetryService $telemetryService,
        private readonly PaymentHealthSeverityEngine $severityEngine,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentNotificationAdapter $paymentNotificationAdapter,
    ) {}

    public function captureForOutletProvider(int $outletId, string $provider, ?Carbon $date = null): PaymentHealthSnapshot
    {
        $snapshotDate = ($date ?? now())->toDateString();
        $report = $this->paymentHealthService->report($provider, $outletId);

        $previous = PaymentHealthSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->where('snapshot_date', '<', $snapshotDate)
            ->orderByDesc('snapshot_date')
            ->first();

        $activeIncidents = (int) PaymentIncident::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->where('status', PaymentIncident::STATUS_OPEN)
            ->count();

        $snapshot = PaymentHealthSnapshot::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'provider' => $provider,
                'snapshot_date' => $snapshotDate,
            ],
            [
                'health_status' => (string) ($report['healthSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY),
                'payment_success_rate' => (float) ($report['paymentSuccessRate'] ?? 100),
                'webhook_success_rate' => (float) ($report['webhookSuccessRate'] ?? 100),
                'stale_payments' => (int) ($report['stalePayments'] ?? 0),
                'failed_webhooks' => (int) ($report['failedWebhooks'] ?? 0),
                'average_processing_time_ms' => (int) ($report['averageProcessingTimeMs'] ?? 0),
                'active_incidents' => $activeIncidents,
            ],
        );

        $currentSeverity = (string) $snapshot->health_status;
        $previousSeverity = (string) ($previous?->health_status ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);

        if ($this->severityEngine->isWorsening($previousSeverity, $currentSeverity)) {
            $this->paymentNotificationAdapter->notifyHealthEscalation(
                $outletId,
                $provider,
                $previousSeverity,
                $currentSeverity,
                $report,
            );
        }

        return $snapshot->fresh();
    }

    public function captureAllOutlets(?Carbon $date = null): Collection
    {
        $providers = $this->paymentGatewayRegistry->registeredProviderKeys();
        $outletIds = Outlet::query()->where('status', 'active')->pluck('id')->map(static fn ($id): int => (int) $id);

        return $outletIds->flatMap(function (int $outletId) use ($providers, $date): Collection {
            return collect($providers)->map(
                fn (string $provider): PaymentHealthSnapshot => $this->captureForOutletProvider($outletId, $provider, $date),
            );
        });
    }

    /**
     * @return array{
     *   providerTrend: list<array{date:string,provider:string,severity:string}>,
     *   paymentSuccessTrend: list<array{date:string,rate:float}>,
     *   webhookTrend: list<array{date:string,rate:float}>,
     *   incidentTrend: list<array{date:string,count:int}>
     * }
     */
    public function trends(?int $outletId, ?string $provider, string $startDate, string $endDate): array
    {
        $query = PaymentHealthSnapshot::query()
            ->whereBetween('snapshot_date', [$startDate, $endDate])
            ->orderBy('snapshot_date');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        if (is_string($provider) && trim($provider) !== '') {
            $query->where('provider', strtolower(trim($provider)));
        }

        $rows = $query->get();

        return [
            'providerTrend' => $rows->map(static fn (PaymentHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'provider' => (string) $row->provider,
                'severity' => (string) $row->health_status,
            ])->values()->all(),
            'paymentSuccessTrend' => $rows->map(static fn (PaymentHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'rate' => (float) $row->payment_success_rate,
            ])->values()->all(),
            'webhookTrend' => $rows->map(static fn (PaymentHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'rate' => (float) $row->webhook_success_rate,
            ])->values()->all(),
            'incidentTrend' => $rows->map(static fn (PaymentHealthSnapshot $row): array => [
                'date' => $row->snapshot_date->toDateString(),
                'count' => (int) $row->active_incidents,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{
     *   provider: string,
     *   uptimePercent: float,
     *   incidents: int,
     *   avgResolutionMinutes: float,
     *   paymentSuccessRate: float
     * }>
     */
    public function reliabilityReport(?int $outletId, int $days = 30): array
    {
        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();
        $providers = $this->paymentGatewayRegistry->registeredProviderKeys();
        $report = [];

        foreach ($providers as $provider) {
            $snapshotQuery = PaymentHealthSnapshot::query()
                ->where('provider', $provider)
                ->whereBetween('snapshot_date', [$startDate, $endDate]);

            if ($outletId !== null && $outletId > 0) {
                $snapshotQuery->where('outlet_id', $outletId);
            }

            $snapshots = $snapshotQuery->get();
            $totalSnapshots = $snapshots->count();
            $uptimePercent = 100.0;
            $avgPaymentSuccess = 100.0;

            if ($totalSnapshots > 0) {
                $nonCritical = $snapshots->where('health_status', '!=', PaymentHealthSeverityEngine::SEVERITY_CRITICAL)->count();
                $uptimePercent = round(($nonCritical / $totalSnapshots) * 100, 1);
                $avgPaymentSuccess = round((float) $snapshots->avg('payment_success_rate'), 1);
            }

            $incidentQuery = PaymentIncident::query()
                ->where('provider', $provider)
                ->whereBetween('opened_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()]);

            if ($outletId !== null && $outletId > 0) {
                $incidentQuery->where('outlet_id', $outletId);
            }

            $incidents = $incidentQuery->get();
            $resolved = $incidents->where('status', PaymentIncident::STATUS_RESOLVED);
            $avgResolution = $resolved->count() > 0
                ? round((float) $resolved->avg('duration_minutes'), 1)
                : 0.0;

            $report[] = [
                'provider' => $provider,
                'uptimePercent' => $uptimePercent,
                'incidents' => $incidents->count(),
                'avgResolutionMinutes' => $avgResolution,
                'paymentSuccessRate' => $avgPaymentSuccess,
            ];
        }

        usort($report, static fn (array $a, array $b): int => ($b['uptimePercent'] ?? 0) <=> ($a['uptimePercent'] ?? 0));

        return $report;
    }
}
