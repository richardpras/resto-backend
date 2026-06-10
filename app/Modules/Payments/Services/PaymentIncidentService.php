<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Models\Modules\Payments\Domain\PaymentIncident;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\Adapters\PaymentNotificationAdapter;
use App\Modules\Payments\Registry\PaymentGatewayRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class PaymentIncidentService
{
    private const WEBHOOK_SPIKE_THRESHOLD = 3;

    public function __construct(
        private readonly PaymentHealthService $paymentHealthService,
        private readonly PaymentHealthSeverityEngine $severityEngine,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly PaymentNotificationAdapter $paymentNotificationAdapter,
    ) {}

    public function checkAllOutlets(): int
    {
        $providers = $this->paymentGatewayRegistry->registeredProviderKeys();
        $outletIds = Outlet::query()->where('status', 'active')->pluck('id')->map(static fn ($id): int => (int) $id);
        $opened = 0;

        foreach ($outletIds as $outletId) {
            foreach ($providers as $provider) {
                $opened += $this->checkOutletProvider($outletId, $provider);
            }
        }

        return $opened;
    }

    public function checkOutletProvider(int $outletId, string $provider): int
    {
        $report = $this->paymentHealthService->report($provider, $outletId);
        $currentSeverity = (string) ($report['healthSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);
        $previousSeverity = $this->previousSeverity($outletId, $provider);

        $opened = 0;
        $configSeverity = (string) ($report['configurationSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($configSeverity === PaymentHealthSeverityEngine::SEVERITY_CRITICAL) {
            $opened += $this->openIncident(
                $outletId,
                $provider,
                PaymentIncident::TYPE_PROVIDER_CRITICAL,
                PaymentHealthSeverityEngine::SEVERITY_CRITICAL,
                'Provider configuration critical',
                'Payment provider credentials or configuration are invalid.',
            ) ? 1 : 0;

            $opened += $this->openIncident(
                $outletId,
                $provider,
                PaymentIncident::TYPE_PROVIDER_UNAVAILABLE,
                PaymentHealthSeverityEngine::SEVERITY_CRITICAL,
                'Payment provider unavailable',
                'Provider cannot accept payments due to configuration or availability issues.',
            ) ? 1 : 0;
        } else {
            $this->resolveOpenIncidents($outletId, $provider, [
                PaymentIncident::TYPE_PROVIDER_CRITICAL,
                PaymentIncident::TYPE_PROVIDER_UNAVAILABLE,
            ]);
        }

        $failedWebhooks = (int) ($report['failedWebhooks'] ?? 0);
        $webhookSeverity = (string) ($report['webhookSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);
        if ($failedWebhooks >= self::WEBHOOK_SPIKE_THRESHOLD || in_array($webhookSeverity, [PaymentHealthSeverityEngine::SEVERITY_HIGH, PaymentHealthSeverityEngine::SEVERITY_CRITICAL], true)) {
            $opened += $this->openIncident(
                $outletId,
                $provider,
                PaymentIncident::TYPE_WEBHOOK_SPIKE,
                $webhookSeverity,
                'Webhook failure spike detected',
                sprintf('%d webhook failure(s); success rate %.1f%%.', $failedWebhooks, (float) ($report['webhookSuccessRate'] ?? 0)),
            ) ? 1 : 0;

            $this->paymentNotificationAdapter->notifyWebhookSpike($outletId, $provider, $failedWebhooks, $report);
        } else {
            $this->resolveOpenIncidents($outletId, $provider, [PaymentIncident::TYPE_WEBHOOK_SPIKE]);
        }

        $staleSeverity = (string) ($report['staleSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);
        $staleCount = (int) ($report['stalePayments'] ?? 0);
        if (in_array($staleSeverity, [PaymentHealthSeverityEngine::SEVERITY_WARNING, PaymentHealthSeverityEngine::SEVERITY_HIGH, PaymentHealthSeverityEngine::SEVERITY_CRITICAL], true) && $staleCount > 5) {
            $opened += $this->openIncident(
                $outletId,
                $provider,
                PaymentIncident::TYPE_STALE_SPIKE,
                $staleSeverity,
                'Stale payment spike detected',
                sprintf('%d stale payment(s) exceed operational threshold.', $staleCount),
            ) ? 1 : 0;
        } else {
            $this->resolveOpenIncidents($outletId, $provider, [PaymentIncident::TYPE_STALE_SPIKE]);
        }

        if ($this->severityEngine->isWorsening($previousSeverity, $currentSeverity)) {
            $this->paymentNotificationAdapter->notifyHealthEscalation(
                $outletId,
                $provider,
                $previousSeverity,
                $currentSeverity,
                $report,
            );
        }

        $this->upsertTodaySnapshotSeverity($outletId, $provider, $currentSeverity, $report);

        return $opened;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIncidents(
        ?int $outletId,
        ?string $provider = null,
        ?string $severity = null,
        ?string $status = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $query = PaymentIncident::query()->orderByDesc('opened_at');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        if (is_string($provider) && trim($provider) !== '') {
            $query->where('provider', strtolower(trim($provider)));
        }

        if (is_string($severity) && trim($severity) !== '') {
            $query->where('severity', strtolower(trim($severity)));
        }

        if (is_string($status) && trim($status) !== '') {
            $query->where('status', strtolower(trim($status)));
        }

        $this->applyDateRange($query, $startDate, $endDate);

        return $query->limit(200)->get()->map(static fn (PaymentIncident $incident): array => [
            'id' => (int) $incident->id,
            'outletId' => (int) $incident->outlet_id,
            'provider' => (string) $incident->provider,
            'incidentType' => (string) $incident->incident_type,
            'severity' => (string) $incident->severity,
            'title' => (string) $incident->title,
            'description' => (string) $incident->description,
            'openedAt' => $incident->opened_at?->toISOString(),
            'resolvedAt' => $incident->resolved_at?->toISOString(),
            'durationMinutes' => $incident->duration_minutes,
            'status' => (string) $incident->status,
        ])->all();
    }

    private function previousSeverity(int $outletId, string $provider): string
    {
        $today = PaymentHealthSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->where('snapshot_date', now()->toDateString())
            ->first();

        if ($today !== null) {
            return (string) $today->health_status;
        }

        $previous = PaymentHealthSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->orderByDesc('snapshot_date')
            ->first();

        return (string) ($previous?->health_status ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY);
    }

    /** @param array<string, mixed> $report */
    private function upsertTodaySnapshotSeverity(int $outletId, string $provider, string $severity, array $report): void
    {
        PaymentHealthSnapshot::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'provider' => $provider,
                'snapshot_date' => now()->toDateString(),
            ],
            [
                'health_status' => $severity,
                'payment_success_rate' => (float) ($report['paymentSuccessRate'] ?? 100),
                'webhook_success_rate' => (float) ($report['webhookSuccessRate'] ?? 100),
                'stale_payments' => (int) ($report['stalePayments'] ?? 0),
                'failed_webhooks' => (int) ($report['failedWebhooks'] ?? 0),
                'average_processing_time_ms' => (int) ($report['averageProcessingTimeMs'] ?? 0),
                'active_incidents' => (int) PaymentIncident::query()
                    ->where('outlet_id', $outletId)
                    ->where('provider', $provider)
                    ->where('status', PaymentIncident::STATUS_OPEN)
                    ->count(),
            ],
        );
    }

    private function openIncident(
        int $outletId,
        string $provider,
        string $type,
        string $severity,
        string $title,
        string $description,
    ): bool {
        $existing = PaymentIncident::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->where('incident_type', $type)
            ->where('status', PaymentIncident::STATUS_OPEN)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'severity' => $severity,
                'description' => $description,
            ]);

            return false;
        }

        PaymentIncident::query()->create([
            'outlet_id' => $outletId,
            'provider' => $provider,
            'incident_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'opened_at' => now(),
            'status' => PaymentIncident::STATUS_OPEN,
        ]);

        if ($severity === PaymentHealthSeverityEngine::SEVERITY_CRITICAL) {
            $this->paymentNotificationAdapter->notifyProviderOutage($outletId, $provider, $title, $description);
        }

        return true;
    }

    /** @param list<string> $types */
    private function resolveOpenIncidents(int $outletId, string $provider, array $types): void
    {
        $incidents = PaymentIncident::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->whereIn('incident_type', $types)
            ->where('status', PaymentIncident::STATUS_OPEN)
            ->get();

        foreach ($incidents as $incident) {
            $resolvedAt = now();
            $duration = (int) max(0, $incident->opened_at?->diffInMinutes($resolvedAt) ?? 0);
            $incident->update([
                'status' => PaymentIncident::STATUS_RESOLVED,
                'resolved_at' => $resolvedAt,
                'duration_minutes' => $duration,
            ]);
        }
    }

    private function applyDateRange(Builder $query, ?string $startDate, ?string $endDate): void
    {
        if (is_string($startDate) && $startDate !== '') {
            $query->where('opened_at', '>=', $startDate.' 00:00:00');
        }

        if (is_string($endDate) && $endDate !== '') {
            $query->where('opened_at', '<=', $endDate.' 23:59:59');
        }
    }
}
