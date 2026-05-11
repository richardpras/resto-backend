<?php

namespace App\Modules\Monitoring\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array<string,mixed>
 */
class DashboardSummaryResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'outletScope' => $this['outletScope'],
            'kpis' => $this['kpis'],
            'hourlyOrders' => $this['hourlyOrders'],
            'topMenus' => $this['topMenus'],
            'recentTransactions' => $this['recentTransactions'],
            'monitoring' => $this['monitoring'] ?? null,
            'crmMetrics' => $this['crmMetrics'] ?? null,
            'bestSellerOtherOutlets' => $this['bestSellerOtherOutlets'] ?? [],
        ];
    }
}

