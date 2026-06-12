<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QrOrderPublicSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_public_lookup_does_not_expose_sensitive_fields(): void
    {
        $requestCode = $this->createRequest();

        $response = $this->getJson('/api/v1/public/qr-orders/'.$requestCode)->assertOk();
        $payload = json_encode($response->json('data'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('confirmed_by_user_id', $payload);
        $this->assertStringNotContainsString('rejected_by_user_id', $payload);
        $this->assertStringNotContainsString('rejectionReason', $payload);
        $this->assertStringNotContainsString('orderId', $payload);
        $this->assertStringNotContainsString('outletId', $payload);
        $this->assertStringNotContainsString('tableId', $payload);
        $this->assertStringNotContainsString('audit', $payload);

        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.customerName');
        $response->assertJsonMissingPath('data.rejectionReason');
        $response->assertJsonMissingPath('data.orderId');
    }

    private function createRequest(): string
    {
        $outlet = Outlet::query()->create([
            'name' => 'Secure Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'sec-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'S1',
            'capacity' => 4,
            'status' => 'active',
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Secure Item',
            'category' => 'main',
            'price' => 10000,
            'available' => true,
        ]);

        $create = $this->postJson('/api/v1/qr-orders', [
            'outletId' => $outlet->id,
            'tableId' => $table->id,
            'customerName' => 'Secret Guest',
            'items' => [['menuItemId' => $menuItem->id, 'qty' => 1]],
        ])->assertCreated();

        return (string) $create->json('data.requestCode');
    }
}
