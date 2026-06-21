<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class QrOrderPendingSummaryTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_pending_summary_returns_lightweight_ids_and_entries_without_items(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [(int) $outlet->id]);

        $requestId = $this->createQrRequest((int) $outlet->id, (int) $table->id, (int) $menuItem->id);

        $response = $this->getJson('/api/v1/qr-orders/pending-summary?outletId='.(int) $outlet->id);

        $response->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.ids.0', (string) $requestId)
            ->assertJsonPath('data.entries.0.id', (string) $requestId)
            ->assertJsonPath('data.entries.0.requestCode', QrOrderRequest::query()->findOrFail($requestId)->request_code)
            ->assertJsonPath('data.entries.0.tableName', $table->name)
            ->assertJsonMissingPath('data.entries.0.items');
    }

    public function test_pending_summary_excludes_non_pending_requests(): void
    {
        [$outlet, $table, $menuItem] = $this->seedQrSetup();
        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [(int) $outlet->id]);

        $pendingId = $this->createQrRequest((int) $outlet->id, (int) $table->id, (int) $menuItem->id);
        $reviewId = $this->createQrRequest((int) $outlet->id, (int) $table->id, (int) $menuItem->id);
        QrOrderRequest::query()->whereKey($reviewId)->update(['status' => 'under_review']);

        $response = $this->getJson('/api/v1/qr-orders/pending-summary?outletId='.(int) $outlet->id);

        $response->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.ids.0', (string) $pendingId);
    }

    public function test_pending_summary_enforces_outlet_access(): void
    {
        [$allowedOutlet, $allowedTable, $allowedMenuItem] = $this->seedQrSetup('Summary Allowed');
        [$forbiddenOutlet, $forbiddenTable, $forbiddenMenuItem] = $this->seedQrSetup('Summary Forbidden');

        $cashier = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($cashier, [(int) $allowedOutlet->id]);

        $this->createQrRequest((int) $allowedOutlet->id, (int) $allowedTable->id, (int) $allowedMenuItem->id);
        $this->createQrRequest((int) $forbiddenOutlet->id, (int) $forbiddenTable->id, (int) $forbiddenMenuItem->id);

        $this->getJson('/api/v1/qr-orders/pending-summary?outletId='.(int) $allowedOutlet->id)
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->getJson('/api/v1/qr-orders/pending-summary?outletId='.(int) $forbiddenOutlet->id)
            ->assertUnprocessable();
    }

    private function createQrRequest(int $outletId, int $tableId, int $menuItemId): int
    {
        $table = RestaurantTable::query()->findOrFail($tableId);
        $this->ensureQrOrderingEnabled();
        $create = $this->submitQrOrder(
            $outletId,
            $tableId,
            $table,
            [['menuItemId' => $menuItemId, 'qty' => 1, 'notes' => 'No chili']],
            ['customerName' => 'Guest QR'],
        );
        $create->assertCreated();

        return (int) $create->json('data.id');
    }

    /** @return array{0: Outlet, 1: RestaurantTable, 2: MenuItem} */
    private function seedQrSetup(string $namePrefix = 'Summary Outlet'): array
    {
        $outlet = Outlet::query()->create([
            'name' => $namePrefix.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower(str_replace(' ', '-', $namePrefix)).'-'.uniqid(),
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Menu-'.uniqid(),
            'category' => 'main',
            'price' => 25000,
            'available' => true,
        ]);

        return [$outlet, $table, $menuItem];
    }
}
