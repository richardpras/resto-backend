<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryFifoConsumptionCostTest extends TestCase
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

    public function test_partial_layer_consumption_returns_blended_unit_cost(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);

        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 12000);

        $unitCost = $service->recordConsumption((int) $ingredient->id, (int) $outlet->id, 15);
        $expected = ((10 * 10000) + (5 * 12000)) / 15;

        $this->assertSame(round($expected, 4), round($unitCost, 4));
    }
}
