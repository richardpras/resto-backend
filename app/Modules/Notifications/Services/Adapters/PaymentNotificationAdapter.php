<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Payments\Services\GatewayProviderResolutionService;
use App\Modules\Payments\Services\PaymentConfigurationHealthService;

final class PaymentNotificationAdapter
{
    private const STALE_PAYMENT_THRESHOLD = 5;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly PaymentConfigurationHealthService $paymentConfigurationHealthService,
        private readonly GatewayProviderResolutionService $gatewayProviderResolutionService,
    ) {}

    /**
     * @param  array<string, mixed>  $monitoringMetrics
     */
    public function syncFromMonitoring(int $outletId, array $monitoringMetrics): void
    {
        if ($outletId < 1) {
            return;
        }

        $provider = $this->gatewayProviderResolutionService->resolve($outletId, null);
        $health = $this->paymentConfigurationHealthService->assessProvider($provider);

        if (($health['status'] ?? '') === 'critical') {
            $missing = is_array($health['missing'] ?? null) ? implode(', ', $health['missing']) : '';
            $this->notificationService->fanOut(
                $outletId,
                'settings.manage',
                UserNotification::SEVERITY_CRITICAL,
                UserNotification::MODULE_PAYMENTS,
                'gateway_critical',
                $provider.'-'.$outletId,
                'Payment gateway configuration critical',
                $missing !== '' ? 'Missing: '.$missing : 'Payment provider configuration requires attention.',
                '/settings/payments/health',
                ['provider' => $provider, 'missing' => $health['missing'] ?? []],
            );
        }

        $webhookFailures = (int) (($monitoringMetrics['reconciliationFailures']['count'] ?? 0));
        if ($webhookFailures > 0) {
            $this->notificationService->fanOut(
                $outletId,
                'pos.use',
                UserNotification::SEVERITY_CRITICAL,
                UserNotification::MODULE_PAYMENTS,
                'webhook_failures',
                (string) $outletId,
                'Payment webhook failures detected',
                sprintf('%d webhook reconciliation failure(s) require attention.', $webhookFailures),
                '/settings/payments/health',
                ['count' => $webhookFailures],
            );
        }

        $staleCount = (int) (($monitoringMetrics['stalePayments']['count'] ?? 0));
        if ($staleCount >= self::STALE_PAYMENT_THRESHOLD) {
            $this->notificationService->fanOut(
                $outletId,
                'pos.use',
                UserNotification::SEVERITY_WARNING,
                UserNotification::MODULE_PAYMENTS,
                'stale_payments',
                (string) $outletId,
                'Stale payments threshold exceeded',
                sprintf('%d payment(s) are pending beyond the stale threshold.', $staleCount),
                '/settings/payments/health',
                ['count' => $staleCount],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function notifyHealthEscalation(
        int $outletId,
        string $provider,
        string $previousSeverity,
        string $currentSeverity,
        array $report,
    ): void {
        $notificationSeverity = match ($currentSeverity) {
            'critical' => UserNotification::SEVERITY_CRITICAL,
            'high' => UserNotification::SEVERITY_WARNING,
            'warning' => UserNotification::SEVERITY_INFO,
            default => UserNotification::SEVERITY_INFO,
        };

        $sourceId = $outletId.'-'.$provider.'-'.$currentSeverity;
        $message = sprintf(
            'Payment health for %s worsened from %s to %s.',
            $provider,
            $previousSeverity,
            $currentSeverity,
        );

        foreach (['settings.manage', 'accounting.manage'] as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                $notificationSeverity,
                UserNotification::MODULE_PAYMENTS,
                'payment_health_alert',
                $sourceId,
                'Payment health severity escalated',
                $message,
                '/settings/payments/health',
                [
                    'provider' => $provider,
                    'previousSeverity' => $previousSeverity,
                    'currentSeverity' => $currentSeverity,
                    'paymentSuccessRate' => (float) ($report['paymentSuccessRate'] ?? 0),
                    'webhookSuccessRate' => (float) ($report['webhookSuccessRate'] ?? 0),
                ],
            );
        }
    }

    public function notifyProviderOutage(int $outletId, string $provider, string $title, string $description): void
    {
        $sourceId = $outletId.'-'.$provider.'-outage';

        foreach (['settings.manage', 'accounting.manage'] as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                UserNotification::SEVERITY_CRITICAL,
                UserNotification::MODULE_PAYMENTS,
                'payment_health_alert',
                $sourceId,
                $title,
                $description,
                '/settings/payments/health',
                ['provider' => $provider, 'alertType' => 'provider_outage'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function notifyWebhookSpike(int $outletId, string $provider, int $failedCount, array $report): void
    {
        if ($failedCount < 3) {
            return;
        }

        $sourceId = $outletId.'-'.$provider.'-webhook-spike';

        foreach (['settings.manage', 'accounting.manage'] as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                UserNotification::SEVERITY_WARNING,
                UserNotification::MODULE_PAYMENTS,
                'payment_health_alert',
                $sourceId,
                'Webhook failure spike detected',
                sprintf('%d webhook failure(s) for %s.', $failedCount, $provider),
                '/settings/payments/health',
                [
                    'provider' => $provider,
                    'failedWebhooks' => $failedCount,
                    'webhookSuccessRate' => (float) ($report['webhookSuccessRate'] ?? 0),
                    'alertType' => 'webhook_spike',
                ],
            );
        }
    }
}
