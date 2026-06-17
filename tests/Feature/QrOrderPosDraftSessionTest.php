<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderPosDraftSessionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_open_in_pos_returns_draft_session_metadata(): void
    {
        [$outlet, , , $requestId, $code] = $this->seedPendingRequest();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson("/api/v1/qr-orders/{$requestId}/open-in-pos")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'posSession' => ['sessionType', 'sourceOrderCode'],
                    'loadPayload' => ['requestId', 'requestCode', 'items', 'subtotal', 'total'],
                ],
            ])
            ->assertJsonPath('data.posSession.sessionType', 'qr_order')
            ->assertJsonPath('data.posSession.sourceOrderCode', $code)
            ->assertJsonPath('data.loadPayload.requestCode', $code);
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem, 3: int, 4: string} */
    private function seedPendingRequest(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Draft Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'draft-'.uniqid(),
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

        return [$outlet, $table, $menuItem, (int) $create->json('data.id'), (string) $create->json('data.requestCode')];
    }
}
