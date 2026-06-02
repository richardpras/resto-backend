<?php

namespace App\Modules\Monitoring\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string,mixed>
 */
class MonitoringMetricsResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'outletScope' => $this['outletScope'],
            'window' => $this['window'],
            'activePosSessions' => $this['activePosSessions'],
            'pendingKitchenTickets' => $this['pendingKitchenTickets'],
            'paymentRate' => $this['paymentRate'],
            'stalePayments' => $this['stalePayments'],
            'qrQueue' => $this['qrQueue'],
            'active_waiter_calls' => $this['active_waiter_calls'] ?? 0,
            'average_waiter_response_time' => $this['average_waiter_response_time'] ?? 0,
            'called_but_unhandled' => $this['called_but_unhandled'] ?? 0,
            'printerQueue' => $this['printerQueue'],
            'reconciliationFailures' => $this['reconciliationFailures'],
            'asyncRecoveryFailures' => $this['asyncRecoveryFailures'],
            'offlineResilience' => $this['offlineResilience'],
            'hardwareBridge' => $this['hardwareBridge'] ?? null,
            'crmMetrics' => $this['crmMetrics'] ?? null,
            'recoverySettlement' => $this['recoverySettlement'] ?? null,
            'paymentGateway' => $this['paymentGateway'] ?? null,
        ];
    }
}
