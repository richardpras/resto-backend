<?php

namespace App\Modules\Payments\Services;

use App\Models\Modules\Payments\Domain\PaymentIncident;

final class PaymentHealthIntelligenceService
{
    public function __construct(
        private readonly PaymentHealthSeverityEngine $severityEngine,
    ) {}

    /**
     * @param  array<string, mixed>  $configReport
     * @param  array<string, mixed>  $telemetry
     * @return array<string, mixed>
     */
    public function enrich(array $configReport, array $telemetry, ?int $outletId, string $provider): array
    {
        $configurationSeverity = $this->severityEngine->configurationSeverity((string) ($configReport['status'] ?? 'healthy'));
        $webhookSeverity = $this->severityEngine->rateSeverity((float) ($telemetry['webhookSuccessRate'] ?? 100));
        $paymentSeverity = $this->severityEngine->rateSeverity((float) ($telemetry['paymentSuccessRate'] ?? 100));
        $staleSeverity = $this->severityEngine->stalePaymentsSeverity((int) ($telemetry['stalePayments'] ?? 0));

        $healthSeverity = $this->severityEngine->aggregateSeverity([
            $configurationSeverity,
            $webhookSeverity,
            $paymentSeverity,
            $staleSeverity,
        ]);

        $openIncidents = 0;
        if ($outletId !== null && $outletId > 0) {
            $openIncidents = (int) PaymentIncident::query()
                ->where('outlet_id', $outletId)
                ->where('provider', $provider)
                ->where('status', PaymentIncident::STATUS_OPEN)
                ->count();
        }

        return array_merge($configReport, [
            'healthSeverity' => $healthSeverity,
            'configurationSeverity' => $configurationSeverity,
            'webhookSeverity' => $webhookSeverity,
            'paymentSeverity' => $paymentSeverity,
            'staleSeverity' => $staleSeverity,
            'paymentSuccessRate' => (float) ($telemetry['paymentSuccessRate'] ?? 100),
            'webhookSuccessRate' => (float) ($telemetry['webhookSuccessRate'] ?? 100),
            'stalePayments' => (int) ($telemetry['stalePayments'] ?? 0),
            'failedWebhooks' => (int) ($telemetry['failedWebhooks'] ?? 0),
            'averageProcessingTimeMs' => (int) ($telemetry['averageProcessingTimeMs'] ?? 0),
            'openIncidents' => $openIncidents,
        ]);
    }
}
