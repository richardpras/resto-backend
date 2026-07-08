<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryCostingMethodSwitchTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_switch_from_moving_average_to_fifo_with_recalculate(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->actingAsInventoryUser($outlet);

        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        StockMovement::query()->insert([
            [
                'inventory_item_id' => $ingredient->id,
                'outlet_id' => $outlet->id,
                'type' => 'purchase',
                'quantity' => 10,
                'source_type' => 'GR',
                'source_id' => 'GR-A',
                'unit_cost' => 10000,
                'total_cost' => 100000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'inventory_item_id' => $ingredient->id,
                'outlet_id' => $outlet->id,
                'type' => 'purchase',
                'quantity' => 10,
                'source_type' => 'GR',
                'source_id' => 'GR-B',
                'unit_cost' => 12000,
                'total_cost' => 120000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(11000.0, round($service->getAverageCost((int) $ingredient->id, (int) $outlet->id), 4));

        $payload = [
            'enableSplitBill' => true,
            'enableMultiPayment' => true,
            'confirmBeforePayment' => true,
            'enableQROrdering' => true,
            'stockEnforcementMode' => 'deferred',
            'allowNegativeStock' => true,
            'inventoryCostingMethod' => InventoryCostingMethod::FIFO,
            'forceRecalculateOnMethodChange' => true,
        ];

        $this->patchJson('/api/v1/system-settings', $payload)->assertOk();

        $fifoCost = $service->getAverageCost((int) $ingredient->id, (int) $outlet->id);
        $this->assertSame(10000.0, $fifoCost);
    }
}
