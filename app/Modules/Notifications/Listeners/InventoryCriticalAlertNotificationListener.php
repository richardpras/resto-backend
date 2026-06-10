<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Inventory\Events\InventoryCriticalAlertRaised;
use App\Modules\Notifications\Services\Adapters\InventoryNotificationAdapter;

final class InventoryCriticalAlertNotificationListener
{
    public function __construct(
        private readonly InventoryNotificationAdapter $inventoryNotificationAdapter,
    ) {}

    public function handle(InventoryCriticalAlertRaised $event): void
    {
        $this->inventoryNotificationAdapter->notifyCriticalAlert($event);
    }
}
