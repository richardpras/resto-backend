<?php

namespace App\Modules\Inventory\Services;

use App\Models\Modules\Inventory\Domain\InventoryIncident;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Notifications\Services\Adapters\InventoryNotificationAdapter;
use App\Modules\Orders\Services\PosAuditLogService;

class InventoryIncidentService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
        private readonly InventoryNotificationAdapter $notificationAdapter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $shortages
     */
    public function recordSaleShortages(Order $order, array $shortages): void
    {
        if ($shortages === []) {
            return;
        }

        $outletId = (int) ($order->outlet_id ?? 0);
        foreach ($shortages as $row) {
            $this->openIncident(
                outletId: $outletId,
                orderId: (int) $order->id,
                ingredientId: isset($row['ingredientId']) ? (int) $row['ingredientId'] : null,
                incidentType: InventoryIncident::TYPE_INSUFFICIENT_ON_SALE,
                severity: InventoryIncident::SEVERITY_WARNING,
                title: 'Insufficient inventory on sale: '.(string) ($row['name'] ?? 'Item'),
                description: sprintf(
                    'Order %s sold with insufficient stock for %s (requested %.2f, available %.2f).',
                    (string) $order->code,
                    (string) ($row['name'] ?? 'Item'),
                    (float) ($row['requested'] ?? 0),
                    (float) ($row['available'] ?? 0),
                ),
                expectedQuantity: (float) ($row['requested'] ?? 0),
                availableQuantity: (float) ($row['available'] ?? 0),
            );
        }

        $this->auditLogService->log(
            'inventory.shortage.detected',
            'order',
            (int) $order->id,
            $outletId,
            null,
            ['shortages' => $shortages, 'orderCode' => (string) $order->code, 'phase' => 'sale'],
        );
        $this->auditLogService->log(
            'inventory.variance_detected',
            'order',
            (int) $order->id,
            $outletId,
            null,
            ['shortages' => $shortages, 'orderCode' => (string) $order->code, 'phase' => 'sale'],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $shortages
     */
    public function recordPostingShortages(Order $order, array $shortages): void
    {
        if ($shortages === []) {
            return;
        }

        $outletId = (int) ($order->outlet_id ?? 0);
        foreach ($shortages as $row) {
            $expected = (float) ($row['required'] ?? $row['requested'] ?? 0);
            $available = (float) ($row['available'] ?? 0);
            $variance = max(0, $expected - $available);

            $incident = $this->openIncident(
                outletId: $outletId,
                orderId: (int) $order->id,
                ingredientId: isset($row['ingredientId']) ? (int) $row['ingredientId'] : null,
                incidentType: InventoryIncident::TYPE_INSUFFICIENT_ON_POSTING,
                severity: $variance > $expected * 0.5 ? InventoryIncident::SEVERITY_CRITICAL : InventoryIncident::SEVERITY_HIGH,
                title: 'Inventory insufficient on posting: '.(string) ($row['name'] ?? 'Ingredient'),
                description: sprintf(
                    'Posting for order %s could not fully consume %s (required %.2f, available %.2f).',
                    (string) $order->code,
                    (string) ($row['name'] ?? 'Ingredient'),
                    $expected,
                    $available,
                ),
                expectedQuantity: $expected,
                availableQuantity: $available,
                variance: $variance,
            );

            $this->notificationAdapter->notifyPostingVariance(
                $outletId,
                (int) $incident->id,
                (string) $order->code,
                (string) ($row['name'] ?? 'Ingredient'),
                $variance,
            );
        }

        $this->auditLogService->log(
            'inventory.variance_detected',
            'order',
            (int) $order->id,
            $outletId,
            null,
            ['shortages' => $shortages, 'orderCode' => (string) $order->code, 'phase' => 'posting'],
        );
    }

    public function notifyPostingFailed(int $outletId, int $orderId, string $orderCode, string $reason): void
    {
        $this->openIncident(
            outletId: $outletId,
            orderId: $orderId,
            ingredientId: null,
            incidentType: InventoryIncident::TYPE_INSUFFICIENT_ON_POSTING,
            severity: InventoryIncident::SEVERITY_HIGH,
            title: 'Inventory posting failed: '.$orderCode,
            description: $reason,
        );

        $this->notificationAdapter->notifyPostingFailed($outletId, $orderId, $orderCode, $reason);

        $this->auditLogService->log(
            'inventory.posting_failed',
            'order',
            $orderId,
            $outletId,
            null,
            ['orderCode' => $orderCode, 'reason' => $reason],
        );
    }

    private function openIncident(
        int $outletId,
        ?int $orderId,
        ?int $ingredientId,
        string $incidentType,
        string $severity,
        string $title,
        string $description,
        ?float $expectedQuantity = null,
        ?float $availableQuantity = null,
        ?float $variance = null,
    ): InventoryIncident {
        return InventoryIncident::query()->create([
            'outlet_id' => $outletId,
            'order_id' => $orderId,
            'ingredient_id' => $ingredientId,
            'incident_type' => $incidentType,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'expected_quantity' => $expectedQuantity,
            'available_quantity' => $availableQuantity,
            'variance' => $variance,
            'status' => InventoryIncident::STATUS_OPEN,
            'opened_at' => now(),
        ]);
    }
}
