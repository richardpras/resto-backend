<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\InventoryCostLayer;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryFifoRebuildTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        SystemSetting::query()->updateOrCreate(['id' => 1], [
            'enable_split_bill' => true,
            'enable_multi_payment' => true,
            'confirm_before_payment' => true,
            'enable_qr_ordering' => true,
            'inventory_costing_method' => InventoryCostingMethod::FIFO,
        ]);
    }

    public function test_recalculate_rebuilds_fifo_layers_from_movements(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);

        StockMovement::query()->insert([
            [
                'inventory_item_id' => $ingredient->id,
                'outlet_id' => $outlet->id,
                'type' => 'purchase',
                'quantity' => 10,
                'source_type' => 'GR',
                'source_id' => 'GR-1',
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
                'source_id' => 'GR-2',
                'unit_cost' => 12000,
                'total_cost' => 120000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'inventory_item_id' => $ingredient->id,
                'outlet_id' => $outlet->id,
                'type' => 'sale',
                'quantity' => 5,
                'source_type' => 'order_payment',
                'source_id' => 'ORD-1',
                'unit_cost' => 10000,
                'total_cost' => 50000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $rebuilt = $service->recalculate((int) $ingredient->id, (int) $outlet->id);
        $this->assertSame(1, $rebuilt);

        $remainingQty = (float) InventoryCostLayer::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('outlet_id', $outlet->id)
            ->sum('qty_remaining');

        $this->assertSame(15.0, $remainingQty);
    }
}
