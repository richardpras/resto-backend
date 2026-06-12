<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderPromoIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_adjust_accepts_promo_discount_in_review_draft(): void
    {
        [$outlet, , $menuItem, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->patchJson("/api/v1/qr-orders/{$requestId}/adjust", [
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 2]],
            'promo' => ['promoId' => 'weekend', 'promoName' => 'Weekend Promo'],
            'promoDiscount' => 5000,
        ])
            ->assertOk()
            ->assertJsonPath('data.discount', 5000)
            ->assertJsonPath('data.total', 45000);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Promo Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'promo-'.uniqid(),
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
        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated();

        return [$outlet, $table, $menuItem, (int) $create->json('data.id')];
    }
}
