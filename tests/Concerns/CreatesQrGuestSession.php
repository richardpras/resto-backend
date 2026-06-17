<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;

trait CreatesQrGuestSession
{
    /** @var array<string, string> */
    private array $guestSessionTokensByTable = [];

    protected function ensureQrOrderingEnabled(): void
    {
        SystemSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'enforce_stock_on_sale' => false,
                'stock_enforcement_mode' => 'deferred',
            ],
        );
        SystemSetting::query()->whereKey(1)->update(['enable_qr_ordering' => true]);
    }

    protected function enableTableQr(RestaurantTable $table): RestaurantTable
    {
        if (! $table->qr_public_id) {
            $table->update([
                'qr_public_id' => 'TBL_'.strtoupper(substr(uniqid(), -8)),
                'qr_enabled' => true,
            ]);
            $table->refresh();
        }

        return $table;
    }

    protected function resolveGuestSessionToken(RestaurantTable $table, ?string $existingToken = null): string
    {
        $table = $this->enableTableQr($table);
        $headers = $existingToken ? ['X-Qr-Guest-Session' => $existingToken] : [];
        $resolve = $this->getJson('/api/v1/qr/tables/'.$table->qr_public_id, $headers)->assertOk();
        $token = (string) $resolve->json('data.guestSession.token');
        $this->assertNotSame('', $token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function qrOrderPayload(
        int $outletId,
        int $tableId,
        RestaurantTable $table,
        string $guestSessionToken,
        array $items,
        array $overrides = [],
    ): array {
        $table = $this->enableTableQr($table);

        return array_merge([
            'outletId' => $outletId,
            'tableId' => $tableId,
            'guestSessionToken' => $guestSessionToken,
            'qrPublicId' => (string) $table->qr_public_id,
            'customerName' => 'Guest',
            'items' => $items,
        ], $overrides);
    }

    protected function guestSessionForTable(RestaurantTable $table): string
    {
        $key = (string) $table->id;
        if (! isset($this->guestSessionTokensByTable[$key])) {
            $this->ensureQrOrderingEnabled();
            $this->guestSessionTokensByTable[$key] = $this->resolveGuestSessionToken($table);
        }

        return $this->guestSessionTokensByTable[$key];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $overrides
     */
    protected function submitQrOrder(
        int $outletId,
        int $tableId,
        RestaurantTable $table,
        array $items,
        array $overrides = [],
    ): \Illuminate\Testing\TestResponse {
        return $this->postJson('/api/v1/qr-orders', $this->qrOrderPayload(
            $outletId,
            $tableId,
            $table,
            $this->guestSessionForTable($table),
            $items,
            $overrides,
        ));
    }

    /**
     * @return array{
     *   outlet: Outlet,
     *   table: RestaurantTable,
     *   menuItem: MenuItem,
     *   guestSessionToken: string,
     *   qrPublicId: string
     * }
     */
    protected function seedQrGuestOrderingContext(): array
    {
        $this->ensureQrOrderingEnabled();

        $outlet = Outlet::query()->create([
            'name' => 'QR Test Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-'.uniqid(),
        ]);

        $qrPublicId = 'TBL_'.strtoupper(substr(uniqid(), -6));
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T1',
            'capacity' => 4,
            'status' => 'active',
            'qr_public_id' => $qrPublicId,
            'qr_enabled' => true,
        ]);

        $menuItem = MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'QR Item',
            'category' => 'main',
            'price' => 10000,
            'available' => true,
        ]);

        $resolve = $this->getJson('/api/v1/qr/tables/'.$qrPublicId)->assertOk();
        $guestSessionToken = (string) $resolve->json('data.guestSession.token');
        $this->assertNotSame('', $guestSessionToken);

        return [
            'outlet' => $outlet,
            'table' => $table,
            'menuItem' => $menuItem,
            'guestSessionToken' => $guestSessionToken,
            'qrPublicId' => $qrPublicId,
        ];
    }

    /**
     * @param  array{outlet: Outlet, table: RestaurantTable, menuItem: MenuItem, guestSessionToken: string, qrPublicId: string}  $ctx
     * @param  array<string, mixed>  $overrides
     */
    protected function postQrOrderRequest(array $ctx, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/qr-orders', array_merge([
            'outletId' => (int) $ctx['outlet']->id,
            'tableId' => (int) $ctx['table']->id,
            'guestSessionToken' => $ctx['guestSessionToken'],
            'qrPublicId' => $ctx['qrPublicId'],
            'customerName' => 'Guest',
            'items' => [
                ['menuItemId' => (int) $ctx['menuItem']->id, 'qty' => 1],
            ],
        ], $overrides));
    }
}
