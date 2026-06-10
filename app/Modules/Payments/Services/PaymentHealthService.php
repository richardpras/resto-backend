<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Modules\Payments\Registry\PaymentGatewayRegistry;

final class PaymentHealthService
{
    public function __construct(
        private readonly PaymentConfigurationHealthService $configurationHealthService,
        private readonly PaymentHealthTelemetryService $telemetryService,
        private readonly PaymentHealthIntelligenceService $intelligenceService,
        private readonly PaymentGatewayRegistry $paymentGatewayRegistry,
        private readonly GatewayProviderResolutionService $gatewayProviderResolutionService,
    ) {}

    /** @return array<string, mixed> */
    public function report(?string $provider = null, ?int $outletId = null): array
    {
        $resolvedProvider = $this->resolveProvider($provider, $outletId);
        $configReport = $this->configurationHealthService->report($resolvedProvider, $outletId);

        $telemetry = ['paymentSuccessRate' => 100.0, 'webhookSuccessRate' => 100.0, 'stalePayments' => 0, 'failedWebhooks' => 0, 'averageProcessingTimeMs' => 0];
        if ($outletId !== null && $outletId > 0) {
            $telemetry = $this->telemetryService->collect($outletId, $resolvedProvider);
        }

        $enriched = $this->intelligenceService->enrich($configReport, $telemetry, $outletId, $resolvedProvider);
        $enriched['reliabilityScore'] = $this->reliabilityScore($outletId, $resolvedProvider);

        return $enriched;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providerRanking(?int $outletId): array
    {
        $providers = $this->paymentGatewayRegistry->registeredProviderKeys();
        $ranked = [];

        foreach ($providers as $provider) {
            $report = $this->report($provider, $outletId);
            $ranked[] = [
                'provider' => $provider,
                'healthSeverity' => (string) ($report['healthSeverity'] ?? PaymentHealthSeverityEngine::SEVERITY_HEALTHY),
                'paymentSuccessRate' => (float) ($report['paymentSuccessRate'] ?? 100),
                'webhookSuccessRate' => (float) ($report['webhookSuccessRate'] ?? 100),
                'openIncidents' => (int) ($report['openIncidents'] ?? 0),
                'reliabilityScore' => (float) ($report['reliabilityScore'] ?? 100),
            ];
        }

        usort($ranked, function (array $a, array $b): int {
            $scoreCompare = ($b['reliabilityScore'] ?? 0) <=> ($a['reliabilityScore'] ?? 0);
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return ($a['openIncidents'] ?? 0) <=> ($b['openIncidents'] ?? 0);
        });

        return $ranked;
    }

    public function reliabilityScore(?int $outletId, string $provider, int $days = 30): float
    {
        if ($outletId === null || $outletId < 1) {
            return 100.0;
        }

        $startDate = now()->subDays($days)->toDateString();
        $endDate = now()->toDateString();

        $query = PaymentHealthSnapshot::query()
            ->where('outlet_id', $outletId)
            ->where('provider', $provider)
            ->whereBetween('snapshot_date', [$startDate, $endDate]);

        $total = (int) $query->count();
        if ($total === 0) {
            return 100.0;
        }

        $healthy = (int) (clone $query)->where('health_status', '!=', PaymentHealthSeverityEngine::SEVERITY_CRITICAL)->count();

        return round(($healthy / $total) * 100, 1);
    }

    private function resolveProvider(?string $provider, ?int $outletId): string
    {
        if (is_string($provider) && trim($provider) !== '') {
            return strtolower(trim($provider));
        }

        $outlet = $outletId !== null && $outletId > 0 ? $outletId : 0;

        return $this->gatewayProviderResolutionService->resolve($outlet, null);
    }
}
