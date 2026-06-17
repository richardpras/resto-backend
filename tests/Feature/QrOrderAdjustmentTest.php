<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderAdjustmentTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_adjust_saves_draft_and_customer_public_lookup_reflects_changes(): void
    {
        [$outlet, , $menuItem, $requestId, $requestCode, $replacement] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->patchJson("/api/v1/qr-orders/{$requestId}/adjust", [
            'items' => [
                ['menuItemId' => $replacement->id, 'qty' => 1],
            ],
            'adjustments' => [
                ['type' => 'removed', 'name' => 'Es Teh', 'reason' => 'Sold Out'],
                ['type' => 'added', 'name' => 'Es Jeruk'],
            ],
        ])->assertOk()->assertJsonPath('data.hasAdjustments', true);

        $this->getJson('/api/v1/public/qr-orders/'.$requestCode)
            ->assertOk()
            ->assertJsonPath('data.customerStatus', 'adjusted')
            ->assertJsonPath('data.items.0.name', (string) $replacement->name);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int, 4: string, 5: MenuItem} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Adjust Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'adjust-'.uniqid(),
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
            'name' => 'Es Teh',
            'category' => 'drinks',
            'price' => 10000,
            'available' => true,
        ]);
        $replacement = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Es Jeruk',
            'category' => 'drinks',
            'price' => 12000,
            'available' => true,
        ]);
        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            (int) $outlet->id,
            (int) $table->id,
            $table,
            [['menuItemId' => (int) $menuItem->id, 'qty' => 1]],
        )->assertCreated();

        return [
            $outlet,
            $table,
            $menuItem,
            (int) $create->json('data.id'),
            (string) $create->json('data.requestCode'),
            $replacement,
        ];
    }
}
