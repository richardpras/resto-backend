<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Inventory\Events\InventoryCriticalAlertRaised;
use App\Modules\Notifications\Services\NotificationService;

final class InventoryNotificationAdapter
{
    public const TYPE_CRITICAL_STOCK = 'inventory_critical_stock';

    public const TYPE_OUT_OF_STOCK = 'inventory_out_of_stock';

    public const TYPE_NEGATIVE_STOCK = 'inventory_negative_stock';

    public const TYPE_VARIANCE_DETECTED = 'inventory_variance_detected';

    public const TYPE_POSTING_FAILED = 'inventory_posting_failed';

    /** @var list<string> */
    private const RECIPIENT_PERMISSIONS = ['inventory.manage', 'purchase.manage', 'settings.manage', 'accounting.manage'];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyCriticalAlert(InventoryCriticalAlertRaised $event): void
    {
        $envelope = $event->broadcastWith();
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $ingredientId = (int) ($payload['ingredient_id'] ?? 0);
        $ingredientName = (string) ($payload['ingredient_name'] ?? 'Ingredient');
        $currentStock = (float) ($payload['current_stock'] ?? 0);
        $minimumStock = (float) ($payload['minimum_stock'] ?? 0);
        $outletId = (int) ($envelope['outlet_id'] ?? 0);

        if ($outletId < 1 || $ingredientId < 1) {
            return;
        }

        [$sourceType, $severity, $sourceId, $title, $message, $actionUrl] = $this->classifyStockAlert(
            $ingredientId,
            $ingredientName,
            $currentStock,
            $minimumStock,
        );

        $metadata = [
            'ingredientId' => $ingredientId,
            'ingredientName' => $ingredientName,
            'currentStock' => $currentStock,
            'minimumStock' => $minimumStock,
            'domainSeverity' => match ($sourceType) {
                self::TYPE_OUT_OF_STOCK => 'high',
                self::TYPE_NEGATIVE_STOCK => 'critical',
                default => 'warning',
            },
        ];

        $this->fanOutToRecipients(
            $outletId,
            $severity,
            $sourceType,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            $metadata,
        );
    }

    public function notifyPostingFailed(int $outletId, int $orderId, string $orderCode, string $reason): void
    {
        if ($outletId < 1) {
            return;
        }

        $this->fanOutToRecipients(
            $outletId,
            UserNotification::SEVERITY_HIGH,
            self::TYPE_POSTING_FAILED,
            'inventory-posting-failed-'.$orderId,
            'Inventory posting failed: '.$orderCode,
            $reason,
            '/inventory?tab=posting',
            ['orderId' => $orderId, 'orderCode' => $orderCode],
        );
    }

    public function notifyPostingVariance(
        int $outletId,
        int $incidentId,
        string $orderCode,
        string $itemName,
        float $variance,
    ): void {
        if ($outletId < 1 || $variance <= 0) {
            return;
        }

        $this->fanOutToRecipients(
            $outletId,
            UserNotification::SEVERITY_WARNING,
            self::TYPE_VARIANCE_DETECTED,
            'inventory-posting-variance-'.$incidentId,
            'Inventory variance on posting: '.$orderCode,
            sprintf('%s variance %.2f on order %s.', $itemName, $variance, $orderCode),
            '/inventory?tab=posting',
            ['orderCode' => $orderCode, 'variance' => $variance, 'incidentId' => $incidentId],
        );
    }

    public function notifyVarianceDetected(int $outletId, float $difference, string $status): void
    {
        if ($outletId < 1 || $status === 'balanced') {
            return;
        }

        $date = now()->toDateString();
        $sourceId = sprintf('variance-%s-outlet-%d', $date, $outletId);

        $this->fanOutToRecipients(
            $outletId,
            UserNotification::SEVERITY_WARNING,
            self::TYPE_VARIANCE_DETECTED,
            $sourceId,
            'Inventory valuation variance detected',
            sprintf(
                'Inventory valuation differs from GL by %.2f (status: %s).',
                $difference,
                $status,
            ),
            '/inventory?tab=valuation',
            [
                'difference' => $difference,
                'status' => $status,
                'snapshotDate' => $date,
            ],
        );
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string}
     */
    private function classifyStockAlert(
        int $ingredientId,
        string $ingredientName,
        float $currentStock,
        float $minimumStock,
    ): array {
        if ($currentStock < 0) {
            return [
                self::TYPE_NEGATIVE_STOCK,
                UserNotification::SEVERITY_CRITICAL,
                'inventory-item-'.$ingredientId.'-negative',
                'Negative stock: '.$ingredientName,
                sprintf('%s stock is %.2f (below zero).', $ingredientName, $currentStock),
                '/inventory?tab=adjustments',
            ];
        }

        if ($currentStock <= 0.0001) {
            return [
                self::TYPE_OUT_OF_STOCK,
                UserNotification::SEVERITY_WARNING,
                'inventory-item-'.$ingredientId.'-outofstock',
                'Out of stock: '.$ingredientName,
                sprintf('%s is out of stock (minimum %.2f).', $ingredientName, $minimumStock),
                '/inventory',
            ];
        }

        return [
            self::TYPE_CRITICAL_STOCK,
            UserNotification::SEVERITY_WARNING,
            'inventory-item-'.$ingredientId,
            'Critical stock: '.$ingredientName,
            sprintf(
                '%s stock is %.2f (minimum %.2f).',
                $ingredientName,
                $currentStock,
                $minimumStock,
            ),
            '/inventory',
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fanOutToRecipients(
        int $outletId,
        string $severity,
        string $sourceType,
        string $sourceId,
        string $title,
        string $message,
        string $actionUrl,
        array $metadata,
    ): void {
        foreach (self::RECIPIENT_PERMISSIONS as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                $severity,
                UserNotification::MODULE_INVENTORY,
                $sourceType,
                $sourceId,
                $title,
                $message,
                $actionUrl,
                $metadata,
            );
        }
    }
}
