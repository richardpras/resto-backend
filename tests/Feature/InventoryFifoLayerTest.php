<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\InventoryCostLayer;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryFifoLayerTest extends TestCase
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

    public function test_fifo_creates_separate_layers_on_multiple_purchases(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        $layers = InventoryCostLayer::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('outlet_id', $outlet->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $layers);
        $this->assertSame(10.0, (float) $layers[0]->qty_remaining);
        $this->assertSame(10000.0, (float) $layers[0]->unit_cost);
        $this->assertSame(12000.0, (float) $layers[1]->unit_cost);
    }

    public function test_fifo_consumption_uses_oldest_layer_first(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        $unitCost = $service->recordConsumption((int) $ingredient->id, (int) $outlet->id, 5);

        $this->assertSame(10000.0, $unitCost);

        $oldest = InventoryCostLayer::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('outlet_id', $outlet->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->first();

        $this->assertSame(5.0, (float) $oldest->qty_remaining);
    }
}
