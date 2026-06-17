<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderLoyaltyIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_adjust_accepts_loyalty_discount_in_review_draft(): void
    {
        [$outlet, , $menuItem, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->patchJson("/api/v1/qr-orders/{$requestId}/adjust", [
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
            'loyalty' => ['points' => 100],
            'loyaltyDiscount' => 10000,
        ])
            ->assertOk()
            ->assertJsonPath('data.discount', 10000)
            ->assertJsonPath('data.total', 15000);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Loyalty Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'loyalty-'.uniqid(),
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'B01',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Nasi Goreng',
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);
        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();

        return [$outlet, $table, $menuItem, (int) $create->json('data.id')];
    }
}
