<?php

namespace Tests\Feature;

use App\Modules\Menu\Services\BundleRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class BundleRecommendationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_detects_frequently_purchased_together_pairs(): void
    {
        $outlet = $this->createValuationOutlet();
        $burger = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);
        $fries = $this->seedMenuWithRecipe((int) $outlet->id, (int) $this->createIngredientForOutlet((int) $outlet->id)->id);

        for ($i = 0; $i < 5; $i++) {
            $orderId = DB::table('orders')->insertGetId([
                'tenant_id' => 1, 'outlet_id' => $outlet->id, 'code' => 'BND-'.$i, 'source' => 'pos',
                'order_type' => 'Dine In', 'status' => 'completed', 'payment_status' => 'paid',
                'paid_total' => 100000, 'total' => 100000, 'subtotal' => 100000, 'tax' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('order_items')->insert([
                ['order_id' => $orderId, 'item_id' => (string) $burger['menuId'], 'name' => 'Burger', 'qty' => 1, 'price' => 50000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now()],
                ['order_id' => $orderId, 'item_id' => (string) $fries['menuId'], 'name' => 'Fries', 'qty' => 1, 'price' => 50000, 'line_total' => 50000, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $bundles = app(BundleRecommendationService::class)->getTopBundles((int) $outlet->id);

        $this->assertNotEmpty($bundles);
        $top = $bundles[0];
        $this->assertGreaterThanOrEqual(80.0, $top['confidencePercent']);
        $this->assertGreaterThan(0, $top['projectedRevenueLiftPercent']);
    }
}
