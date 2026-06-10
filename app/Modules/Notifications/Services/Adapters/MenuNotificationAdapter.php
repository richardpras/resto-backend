<?php

namespace App\Modules\Notifications\Services\Adapters;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\NotificationService;

final class MenuNotificationAdapter
{
    public const TYPE_FOOD_COST = 'food_cost_alert';

    public const TYPE_MARGIN_EROSION = 'margin_erosion_alert';

    public const TYPE_DEAD_STOCK = 'dead_stock_alert';

    public const TYPE_SLOW_MOVING = 'slow_moving_stock_alert';

    public const TYPE_YIELD_LOSS = 'yield_loss_alert';

    public const TYPE_WASTE = 'waste_alert';

    public const TYPE_SUPPLIER_PRICE = 'supplier_price_alert';

    public const TYPE_MENU_PROFITABILITY = 'menu_profitability_alert';

    /** @var list<string> */
    private const BASE_RECIPIENT_PERMISSIONS = ['analytics.view', 'inventory.manage', 'purchase.manage'];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function notifyAutomationAlert(AutomationAlert $alert): void
    {
        $outletId = (int) $alert->outlet_id;
        if ($outletId < 1) {
            return;
        }

        $mapping = $this->mapAlert($alert);
        if ($mapping === null) {
            return;
        }

        [
            $sourceType,
            $severity,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            $metadata,
            $domainSeverity,
        ] = $mapping;

        if ($domainSeverity !== null) {
            $metadata['domainSeverity'] = $domainSeverity;
        }

        $metadata['alertId'] = (int) $alert->id;
        $metadata['alertType'] = (string) $alert->alert_type;

        $permissions = self::BASE_RECIPIENT_PERMISSIONS;
        if (in_array($domainSeverity, ['high', 'critical'], true)) {
            $permissions[] = 'settings.manage';
        }

        foreach ($permissions as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                $severity,
                UserNotification::MODULE_MENU_INTELLIGENCE,
                $sourceType,
                $sourceId,
                $title,
                $message,
                $actionUrl,
                $metadata,
            );
        }
    }

    /**
     * @return array{
     *     0: string,
     *     1: string,
     *     2: string,
     *     3: string,
     *     4: string,
     *     5: string,
     *     6: array<string, mixed>,
     *     7: string|null
     * }|null
     */
    private function mapAlert(AutomationAlert $alert): ?array
    {
        $payload = is_array($alert->payload_json) ? $alert->payload_json : [];
        $title = (string) $alert->title;
        $message = (string) $alert->description;

        return match ((string) $alert->alert_type) {
            'food_cost' => [
                self::TYPE_FOOD_COST,
                UserNotification::SEVERITY_WARNING,
                'food-cost-outlet-'.$alert->outlet_id,
                $title,
                $message,
                '/dashboard/menu?tab=food-cost',
                $payload,
                null,
            ],
            'margin_erosion' => [
                self::TYPE_MARGIN_EROSION,
                UserNotification::SEVERITY_WARNING,
                $this->menuItemSourceId('margin-menu', $payload),
                $title,
                $message,
                '/dashboard/menu?tab=profitability',
                $payload,
                null,
            ],
            'dead_stock' => [
                self::TYPE_DEAD_STOCK,
                UserNotification::SEVERITY_WARNING,
                $this->deadStockSourceId($alert, $payload),
                $title,
                $message,
                '/dashboard/menu?tab=dead-stock',
                $payload,
                'high',
            ],
            'star_to_plowhorse' => [
                self::TYPE_SLOW_MOVING,
                UserNotification::SEVERITY_INFO,
                $this->menuItemSourceId('slow-moving-menu', $payload),
                $title,
                $message,
                '/dashboard/menu?tab=inventory',
                $payload,
                null,
            ],
            'star_to_dog', 'menu_removal' => [
                self::TYPE_MENU_PROFITABILITY,
                UserNotification::SEVERITY_WARNING,
                $this->menuItemSourceId('profitability-menu', $payload),
                $title,
                $message,
                '/dashboard/menu?tab=profitability',
                $payload,
                'high',
            ],
            'yield_loss' => [
                self::TYPE_YIELD_LOSS,
                UserNotification::SEVERITY_WARNING,
                $this->menuItemSourceId('yield-item', $payload),
                $title,
                $message,
                '/dashboard/menu?tab=yield',
                $payload,
                null,
            ],
            'inventory_value_spike' => [
                self::TYPE_WASTE,
                UserNotification::SEVERITY_WARNING,
                $this->dedupeSourceId('waste-outlet-'.$alert->outlet_id, $payload, (string) $alert->id),
                $title,
                $message,
                '/dashboard/menu?tab=waste',
                $payload,
                null,
            ],
            'optimization_ingredient' => [
                self::TYPE_SUPPLIER_PRICE,
                UserNotification::SEVERITY_WARNING,
                $this->menuItemSourceId('supplier-price-menu', $payload, 'recommendation'),
                $title,
                $message,
                '/dashboard/menu?tab=supplier-costs',
                $payload,
                null,
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function menuItemSourceId(string $prefix, array $payload, ?string $nestedKey = null): string
    {
        $menuItemId = $this->resolveMenuItemId($payload, $nestedKey);

        return $menuItemId !== null
            ? $prefix.'-'.$menuItemId
            : $prefix.'-alert-'.($payload['dedupeKey'] ?? 'unknown');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deadStockSourceId(AutomationAlert $alert, array $payload): string
    {
        $ingredients = $payload['ingredients'] ?? [];
        if (is_array($ingredients) && count($ingredients) === 1) {
            $first = $ingredients[0];
            if (is_array($first) && isset($first['ingredientId'])) {
                return 'dead-stock-item-'.$first['ingredientId'];
            }
            if (is_array($first) && isset($first['id'])) {
                return 'dead-stock-item-'.$first['id'];
            }
        }

        return $this->dedupeSourceId('dead-stock-outlet-'.$alert->outlet_id, $payload, (string) $alert->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dedupeSourceId(string $prefix, array $payload, string $fallback): string
    {
        $dedupeKey = $payload['dedupeKey'] ?? null;

        return is_string($dedupeKey) && $dedupeKey !== ''
            ? $prefix.'-'.str_replace(':', '-', $dedupeKey)
            : $prefix.'-'.$fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveMenuItemId(array $payload, ?string $nestedKey = null): ?string
    {
        if ($nestedKey !== null) {
            $nested = $payload[$nestedKey] ?? null;
            if (is_array($nested) && isset($nested['menuItemId'])) {
                return (string) $nested['menuItemId'];
            }
        }

        if (isset($payload['menuItemId'])) {
            return (string) $payload['menuItemId'];
        }

        return null;
    }
}
