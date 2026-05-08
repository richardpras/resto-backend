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
            'printerQueue' => $this['printerQueue'],
            'reconciliationFailures' => $this['reconciliationFailures'],
            'asyncRecoveryFailures' => $this['asyncRecoveryFailures'],
            'offlineResilience' => $this['offlineResilience'],
            'hardwareBridge' => $this['hardwareBridge'] ?? null,
            'crmMetrics' => $this['crmMetrics'] ?? null,
        ];
    }
}
