<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderSearchTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_search_by_order_code_returns_review_payload(): void
    {
        [$outlet, $table, $menuItem, $requestCode, $requestId] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->getJson('/api/v1/qr-orders/search?code='.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $requestId)
            ->assertJsonPath('data.requestCode', $requestCode);
    }

    public function test_search_extracts_code_from_order_url(): void
    {
        [$outlet, , , $requestCode] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->getJson('/api/v1/qr-orders/search?code='.urlencode('https://app.test/qr/order/'.$requestCode))
            ->assertOk()
            ->assertJsonPath('data.requestCode', $requestCode);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: string, 4: int} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Review Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'review-'.uniqid(),
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

        return [$outlet, $table, $menuItem, (string) $create->json('data.requestCode'), (int) $create->json('data.id')];
    }
}
