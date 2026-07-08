<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Terminals\Support\TerminalOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TerminalOrderSplitsSyncReplayTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_sync_batch_replays_order_splits_sync_with_client_item_id(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Split Sync Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'spl-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'split-device-'.$outlet->id,
        ])->assertOk();

        $orderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'SPLIT-'.uniqid(),
            'source' => 'pos',
            'order_channel' => 'dine_in',
            'service_mode' => 'dine_in',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => 'line-1',
            'name' => 'Nasi Goreng',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = OrderItem::query()->find($itemId);
        self::assertNotNull($item);

        $fp = hash('sha256', 'split-sync-'.$orderId.'-'.Str::uuid()->toString());
        $response = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'split-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fp,
                    'operationType' => TerminalOperationType::ORDER_SPLITS_SYNC,
                    'payload' => [
                        'orderId' => $orderId,
                        'persons' => [
                            [
                                'splitType' => 'by_person',
                                'label' => 'Person A',
                                'items' => [
                                    [
                                        'clientItemId' => (string) $item->item_id,
                                        'orderItemId' => 0,
                                        'qty' => 1,
                                        'amount' => 10000.0,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.results.0.status', 'applied');
        $this->assertDatabaseHas('order_splits', ['order_id' => $orderId, 'label' => 'Person A']);
    }
}
