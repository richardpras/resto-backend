<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QrOrderPublicLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_public_lookup_returns_sanitized_order_details(): void
    {
        [$outlet, $table, $menuItem, $requestCode] = $this->seedPendingRequest();

        $response = $this->getJson('/api/v1/public/qr-orders/'.$requestCode);
        $response->assertOk();
        $response->assertJsonPath('data.orderCode', $requestCode);
        $response->assertJsonPath('data.tableName', (string) $table->name);
        $response->assertJsonPath('data.outletName', (string) $outlet->name);
        $response->assertJsonPath('data.customerStatus', 'pending_review');
        $response->assertJsonPath('data.customerStatusLabel', 'Waiting for cashier review');
        $response->assertJsonPath('data.items.0.name', (string) $menuItem->name);
        $response->assertJsonPath('data.items.0.quantity', 2);
        $response->assertJsonPath('data.items.0.unitPrice', 25000);
        $response->assertJsonPath('data.items.0.lineTotal', 50000);
        $response->assertJsonPath('data.subtotal', 50000);
        $response->assertJsonPath('data.total', 50000);
    }

    public function test_invalid_order_code_returns_not_found(): void
    {
        $this->getJson('/api/v1/public/qr-orders/QRO-NOTREAL99')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found or expired.');
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: string} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Mountain Cafe',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'public-qr-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'B01',
            'capacity' => 4,
            'status' => 'active',
            'qr_public_id' => 'tbl-public-'.uniqid(),
            'qr_enabled' => true,
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
            [['menuItemId' => (int) $menuItem->id, 'qty' => 2, 'notes' => 'Pedas']],
        );
        $create->assertCreated();

        return [$outlet, $table, $menuItem, (string) $create->json('data.requestCode')];
    }
}
