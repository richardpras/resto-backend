<?php

namespace Database\Seeders\Support;

use App\Models\Member;
use App\Models\Modules\GiftCards\Domain\GiftCardEvent;
use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Hardware\Domain\HardwareCommandLog;
use App\Models\Modules\Hardware\Domain\HardwareDeviceEvent;
use App\Models\Modules\Hardware\Domain\HardwareDeviceSession;
use App\Models\Modules\Hardware\Domain\PrinterDeviceProfile;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Inventory\Domain\StockMovement;
use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Kitchen\Domain\KitchenTicketItem;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyMembershipTier;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\Loyalty\Domain\LoyaltyRewardRedemption;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Menu\Domain\MenuItemOutlet;
use App\Models\Modules\Menu\Domain\MenuRecipe;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\Modules\Orders\Domain\Payment;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Payments\Domain\PaymentTransactionEvent;
use App\Models\Modules\Payments\Domain\PaymentWebhookReceipt;
use App\Models\Modules\Print\Domain\FiscalInvoice;
use App\Models\Modules\Print\Domain\InvoiceSequence;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrintJobEvent;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Terminals\Domain\TerminalDevice;
use App\Models\Modules\Terminals\Domain\TerminalSyncConflictEvent;
use App\Models\Modules\Terminals\Domain\TerminalSyncOperation;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DemoDatasetSeederService
{
    private const BASE_TS = '2026-05-01 07:00:00';
    private const DEMO_TENANT_ID = 1;

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function outlets(): array
    {
        return [
            'A' => ['code' => 'DEMO-CENTRAL', 'name' => 'Resto Nusantara — Central'],
            'B' => ['code' => 'DEMO-WEST', 'name' => 'Resto Nusantara — West'],
        ];
    }

    public static function baseTime(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::BASE_TS);
    }

    public static function seedFoundation(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->updateOrCreate(
                    ['code' => $spec['code']],
                    [
                        'name' => $spec['name'],
                        'address' => $key === 'A' ? 'Jl. Sudirman 88, Jakarta' : 'Jl. Asia Afrika 21, Bandung',
                        'phone' => $key === 'A' ? '0215550101' : '0225550102',
                        'manager' => $key === 'A' ? 'Dewi Laksmi' : 'Arif Hidayat',
                        'status' => 'active',
                        'invoice_prefix' => 'INV-'.$key,
                        'order_prefix' => 'ORD-'.$key,
                    ],
                );

                OutletReceiptSetting::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id],
                    [
                        'receipt_header' => $spec['name'],
                        'receipt_footer' => 'Terima kasih - Demo POS/UAT',
                        'show_logo' => true,
                        'show_tax_breakdown' => true,
                    ],
                );
            }

            $permissionMap = Permission::query()->pluck('id', 'code');
            $roles = [
                'Demo Outlet Manager' => ['dashboard.view_own_outlet', 'pos.use', 'kitchen.use', 'menu.manage', 'inventory.manage', 'tables.view', 'tables.manage', 'qr_orders.view', 'reports.view', 'members.manage', 'orders.recovery.read', 'orders.recovery.request', 'orders.recovery.approve'],
                'Demo Cashier' => ['pos.use', 'members.manage', 'tables.view', 'qr_orders.view', 'orders.recovery.read', 'orders.recovery.request'],
                'Demo Kitchen' => ['kitchen.use', 'orders.recovery.read', 'orders.recovery.request'],
                'Demo Waiter' => ['pos.use', 'tables.view', 'qr_orders.view'],
                'Demo Inventory Admin' => ['inventory.manage', 'purchase.manage', 'reports.view'],
            ];

            $roleIds = [];
            foreach ($roles as $name => $codes) {
                $role = Role::query()->updateOrCreate(['name' => $name], ['description' => 'Deterministic demo role']);
                $ids = [];
                foreach ($codes as $code) {
                    if (isset($permissionMap[$code])) {
                        $ids[] = $permissionMap[$code];
                    }
                }
                $role->permissions()->sync($ids);
                $roleIds[$name] = $role->id;
            }

            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $domain = strtolower($key).'.demo.resto.local';
                $users = [
                    ['name' => "Manager {$key}", 'email' => "manager@{$domain}", 'pin' => '1111', 'password' => 'demo123', 'role' => 'Demo Outlet Manager'],
                    ['name' => "Cashier {$key} One", 'email' => "cashier1@{$domain}", 'pin' => '2221', 'password' => 'demo123', 'role' => 'Demo Cashier'],
                    ['name' => "Cashier {$key} Two", 'email' => "cashier2@{$domain}", 'pin' => '2222', 'password' => 'demo123', 'role' => 'Demo Cashier'],
                    ['name' => "Kitchen {$key} One", 'email' => "kitchen1@{$domain}", 'pin' => '3331', 'password' => 'demo123', 'role' => 'Demo Kitchen'],
                    ['name' => "Kitchen {$key} Two", 'email' => "kitchen2@{$domain}", 'pin' => '3332', 'password' => 'demo123', 'role' => 'Demo Kitchen'],
                    ['name' => "Waiter {$key}", 'email' => "waiter@{$domain}", 'pin' => '4444', 'password' => 'demo123', 'role' => 'Demo Waiter'],
                    ['name' => "Inventory {$key}", 'email' => "inventory@{$domain}", 'pin' => '5555', 'password' => 'demo123', 'role' => 'Demo Inventory Admin'],
                ];

                foreach ($users as $row) {
                    $user = User::query()->updateOrCreate(
                        ['email' => $row['email']],
                        ['name' => $row['name'], 'password' => $row['password'], 'pin_hash' => $row['pin']],
                    );
                    $user->roles()->sync([$roleIds[$row['role']]]);
                    $user->outlets()->syncWithoutDetaching([$outlet->id]);
                }

                foreach (['Cash', 'QRIS', 'Midtrans', 'Xendit', 'Store Credit', 'Gift Card'] as $methodName) {
                    $id = strtolower("demo_{$key}_".str_replace(' ', '_', $methodName));
                    PaymentMethod::query()->updateOrCreate(
                        ['id' => $id],
                        ['name' => "{$methodName} {$key}", 'type' => 'custom', 'integration' => strtolower(str_replace(' ', '_', $methodName)), 'fee' => 0, 'status' => 'active'],
                    );
                }
            }
        });
    }

    public static function seedMenuAndInventory(): void
    {
        DB::transaction(function (): void {
            $menuBlueprint = [
                ['sku' => 'FD001', 'name' => 'Nasi Goreng Nusantara', 'category' => 'Food', 'price' => 45000, 'cost' => 21000, 'station' => 'kitchen', 'ingredients' => ['Rice' => 0.3, 'Egg' => 1, 'Chicken' => 0.12, 'Sambal' => 0.03]],
                ['sku' => 'FD002', 'name' => 'Ayam Bakar Madu', 'category' => 'Food', 'price' => 52000, 'cost' => 24000, 'station' => 'kitchen', 'ingredients' => ['Chicken' => 0.2, 'Honey Sauce' => 0.04, 'Rice' => 0.25]],
                ['sku' => 'BV001', 'name' => 'Es Teh Manis', 'category' => 'Beverage', 'price' => 15000, 'cost' => 4500, 'station' => 'bar', 'ingredients' => ['Tea Leaves' => 0.01, 'Sugar' => 0.02, 'Ice Cube' => 0.15]],
                ['sku' => 'CF001', 'name' => 'Cappuccino', 'category' => 'Beverage', 'price' => 32000, 'cost' => 12000, 'station' => 'bar', 'ingredients' => ['Coffee Beans' => 0.018, 'Milk' => 0.12, 'Sugar' => 0.01]],
                ['sku' => 'DS001', 'name' => 'Pisang Goreng Coklat', 'category' => 'Dessert', 'price' => 28000, 'cost' => 11000, 'station' => 'kitchen', 'ingredients' => ['Banana' => 0.2, 'Chocolate Sauce' => 0.03, 'Flour' => 0.1]],
                ['sku' => 'BR001', 'name' => 'Mojito Mocktail', 'category' => 'Bar', 'price' => 38000, 'cost' => 13000, 'station' => 'bar', 'ingredients' => ['Soda Water' => 0.2, 'Mint Leaves' => 0.01, 'Lime' => 0.05, 'Sugar' => 0.01]],
            ];

            $ingredientStock = [
                'Rice' => [220, 80],
                'Egg' => [120, 30],
                'Chicken' => [95, 20],
                'Sambal' => [18, 6],
                'Honey Sauce' => [14, 4],
                'Tea Leaves' => [6, 2],
                'Sugar' => [45, 12],
                'Ice Cube' => [90, 30],
                'Coffee Beans' => [12, 3],
                'Milk' => [34, 8],
                'Banana' => [44, 10],
                'Chocolate Sauce' => [16, 4],
                'Flour' => [26, 7],
                'Soda Water' => [32, 8],
                'Mint Leaves' => [8, 2],
                'Lime' => [20, 5],
            ];

            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $ingredientIds = [];
                foreach ($ingredientStock as $name => $stockPair) {
                    $ingredient = Ingredient::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'name' => $name],
                        ['tenant_id' => self::DEMO_TENANT_ID, 'type' => 'ingredient', 'unit' => 'kg', 'stock' => $stockPair[0], 'min' => $stockPair[1], 'price' => $stockPair[1] * 1000, 'notes' => 'Demo ingredient'],
                    );
                    $ingredientIds[$name] = $ingredient->id;
                    InventoryStock::query()->updateOrCreate(
                        ['ingredient_id' => $ingredient->id, 'outlet_id' => $outlet->id],
                        ['stock' => $stockPair[0]],
                    );
                }

                foreach ($menuBlueprint as $row) {
                    $menuItem = MenuItem::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'name' => $row['name']],
                        ['tenant_id' => self::DEMO_TENANT_ID, 'category' => $row['category'], 'emoji' => '🍽️', 'price' => $row['price'], 'available' => true],
                    );
                    MenuItemOutlet::query()->updateOrCreate(
                        ['menu_item_id' => $menuItem->id, 'outlet_id' => (string) $outlet->id],
                        ['is_active' => true, 'price_override' => $row['price'], 'name_override' => null, 'receipt_name' => "{$row['sku']} {$row['name']}"],
                    );
                    foreach ($row['ingredients'] as $ingredientName => $qty) {
                        MenuRecipe::query()->updateOrCreate(
                            ['menu_item_id' => $menuItem->id, 'inventory_item_id' => $ingredientIds[$ingredientName]],
                            ['quantity' => $qty],
                        );
                    }
                }
            }
        });
    }

    public static function seedOutletOps(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();

                foreach ([['M1', 'Main Hall', 4], ['M2', 'Main Hall', 4], ['T1', 'Terrace', 2], ['VIP1', 'VIP', 8]] as $index => $table) {
                    RestaurantTable::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'code' => "{$key}-{$table[0]}"],
                        ['name' => "Table {$table[0]}", 'capacity' => $table[2], 'zone' => $table[1], 'status' => 'active', 'active' => true],
                    );
                }

                $profiles = [
                    ['code' => "{$key}-CASHIER", 'name' => "Cashier Printer {$key}", 'station' => 'cashier', 'health' => 'healthy', 'queue' => 'idle'],
                    ['code' => "{$key}-KITCHEN", 'name' => "Kitchen Printer {$key}", 'station' => 'kitchen', 'health' => 'healthy', 'queue' => 'normal'],
                    ['code' => "{$key}-BAR", 'name' => "Bar Printer {$key}", 'station' => 'bar', 'health' => 'degraded', 'queue' => 'backlog'],
                ];

                $profileIds = [];
                foreach ($profiles as $p) {
                    $profile = PrinterProfile::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'code' => $p['code']],
                        [
                            'tenant_id' => null,
                            'name' => $p['name'],
                            'station' => $p['station'],
                            'connection_type' => 'bridge',
                            'device_identifier' => "bridge://{$outlet->id}/{$p['code']}",
                            'ip_address' => $key === 'A' ? '10.10.1.21' : '10.10.2.21',
                            'bluetooth_name' => "{$p['station']}-bt-{$key}",
                            'bluetooth_address' => "00:1A:7D:DA:71:{$key}1",
                            'pairing_state' => 'paired',
                            'last_connected_at' => self::baseTime()->addDays(9),
                            'reconnect_metadata' => ['lastReason' => 'signal-drop', 'reconnectsToday' => 2],
                            'signal_metadata' => ['rssi' => -67, 'transport' => 'hybrid'],
                            'endpoint' => "/dev/usb/{$p['station']}",
                            'is_active' => true,
                            'health_status' => $p['health'],
                            'queue_state' => $p['queue'],
                            'last_heartbeat_at' => now()->subMinutes($p['station'] === 'bar' ? 30 : 2),
                            'retry_policy' => ['maxAttempts' => 5, 'backoff' => 'exponential'],
                            'meta' => ['bridge' => ['deviceId' => "bridge-{$key}-01"], 'lan' => ['port' => 9100], 'bluetooth' => ['enabled' => true]],
                        ],
                    );
                    $profileIds[$p['station']] = $profile->id;
                }

                $routes = [
                    ['print_type' => 'receipt', 'scope' => 'default', 'station' => 'cashier', 'category' => null, 'priority' => 10],
                    ['print_type' => 'kitchen', 'scope' => 'category', 'station' => 'kitchen', 'category' => 'Food', 'priority' => 20],
                    ['print_type' => 'kitchen', 'scope' => 'category', 'station' => 'bar', 'category' => 'Beverage', 'priority' => 20],
                    ['print_type' => 'kitchen', 'scope' => 'category', 'station' => 'bar', 'category' => 'Bar', 'priority' => 30],
                    ['print_type' => 'kitchen', 'scope' => 'fallback', 'station' => 'kitchen', 'category' => null, 'priority' => 999],
                ];
                foreach ($routes as $route) {
                    PrinterRoute::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'print_type' => $route['print_type'], 'route_scope' => $route['scope'], 'station' => $route['station'], 'category' => $route['category']],
                        ['tenant_id' => null, 'printer_profile_id' => $profileIds[$route['station']], 'priority' => $route['priority'], 'is_active' => true, 'meta' => ['fallback' => $route['scope'] === 'fallback']],
                    );
                }
            }
        });
    }

    public static function seedTransactions(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $cashiers = User::query()->where('email', 'like', "cashier%@".strtolower($key).'.demo.resto.local')->get()->values();
                $tables = RestaurantTable::query()->where('outlet_id', $outlet->id)->get()->values();
                $menuItems = MenuItem::query()->where('outlet_id', $outlet->id)->get()->values();
                if ($cashiers->count() < 2 || $tables->isEmpty() || $menuItems->isEmpty()) {
                    continue;
                }

                for ($c = 0; $c < $cashiers->count(); $c++) {
                    for ($s = 0; $s < 2; $s++) {
                        $opened = self::baseTime()->addDays($c * 2 + $s)->addHours($s === 0 ? 6 : 14);
                        PosSession::query()->updateOrCreate(
                            ['outlet_id' => $outlet->id, 'opened_by_user_id' => $cashiers[$c]->id, 'opened_at' => $opened],
                            [
                                'closed_by_user_id' => $s === 0 ? $cashiers[$c]->id : null,
                                'status' => $s === 0 ? 'closed' : 'open',
                                'opening_cash' => 500000,
                                'closing_cash' => $s === 0 ? 1300000 : null,
                                'cash_variance' => $s === 0 ? 12000 : null,
                                'closed_at' => $s === 0 ? $opened->addHours(8) : null,
                                'notes' => $s === 1 ? 'stale-open-demo' : 'closed-shift-demo',
                            ],
                        );
                    }
                }

                $sessions = PosSession::query()->where('outlet_id', $outlet->id)->orderBy('id')->get()->values();
                for ($i = 1; $i <= 160; $i++) {
                    $status = $i <= 110 ? 'completed' : ($i <= 130 ? 'cancelled' : ($i <= 145 ? 'ready' : 'pending'));
                    $paymentStatus = $status === 'completed' ? 'paid' : ($status === 'cancelled' ? 'unpaid' : 'partial');
                    $serviceMode = $i % 3 === 0 ? 'takeaway' : 'dine_in';
                    $channel = $i % 5 === 0 ? 'qr' : 'pos';
                    $orderedAt = self::baseTime()->addDays(10)->addMinutes($i * 11);
                    $table = $tables[($i - 1) % $tables->count()];
                    $session = $sessions[($i - 1) % $sessions->count()];
                    $code = "{$spec['code']}-ORD-".str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                    $base = (float) $menuItems[($i - 1) % $menuItems->count()]->price + (float) $menuItems[$i % $menuItems->count()]->price;
                    $tax = round($base * 0.11, 2);
                    $total = $base + $tax;

                    $order = Order::query()->updateOrCreate(
                        ['code' => $code],
                        [
                            'tenant_id' => null,
                            'outlet_id' => $outlet->id,
                            'pos_session_id' => $session->id,
                            'source' => $channel === 'qr' ? 'qr' : 'pos',
                            'order_channel' => $channel,
                            'service_mode' => $serviceMode,
                            'order_type' => $serviceMode,
                            'status' => $status,
                            'payment_status' => $paymentStatus,
                            'kitchen_status' => $status === 'completed' ? 'completed' : ($status === 'ready' ? 'ready' : 'queued'),
                            'subtotal' => $base,
                            'tax' => $tax,
                            'total' => $total,
                            'discount_amount' => $i % 7 === 0 ? 5000 : 0,
                            'paid_total' => $paymentStatus === 'paid' ? $total : ($paymentStatus === 'partial' ? $total / 2 : 0),
                            'balance_due' => $paymentStatus === 'paid' ? 0 : ($paymentStatus === 'partial' ? $total / 2 : $total),
                            'customer_name' => "Demo Customer {$key}-{$i}",
                            'customer_phone' => '0812'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                            'table_number' => $table->name,
                            'table_id' => $table->id,
                            'table_name' => $table->name,
                            'confirmed_at' => $orderedAt->addMinutes(2),
                            'stock_deducted_at' => $paymentStatus === 'paid' ? $orderedAt->addMinutes(7) : null,
                            'is_posted' => $paymentStatus === 'paid',
                        ],
                    );

                    $itemA = $menuItems[($i - 1) % $menuItems->count()];
                    $itemB = $menuItems[$i % $menuItems->count()];
                    $orderItemA = OrderItem::query()->updateOrCreate(
                        ['order_id' => $order->id, 'item_id' => "{$itemA->id}-A"],
                        ['name' => $itemA->name, 'emoji' => $itemA->emoji, 'qty' => 1, 'price' => $itemA->price, 'line_total' => $itemA->price, 'notes' => null],
                    );
                    $orderItemB = OrderItem::query()->updateOrCreate(
                        ['order_id' => $order->id, 'item_id' => "{$itemB->id}-B"],
                        ['name' => $itemB->name, 'emoji' => $itemB->emoji, 'qty' => 1, 'price' => $itemB->price, 'line_total' => $itemB->price, 'notes' => $i % 11 === 0 ? 'Less sugar' : null],
                    );

                    if ($i % 4 === 0) {
                        $splitA = OrderSplit::query()->updateOrCreate(['order_id' => $order->id, 'label' => 'Guest A'], ['split_type' => 'item', 'status' => 'closed']);
                        $splitB = OrderSplit::query()->updateOrCreate(['order_id' => $order->id, 'label' => 'Guest B'], ['split_type' => 'item', 'status' => 'closed']);
                        OrderSplitItem::query()->updateOrCreate(['order_split_id' => $splitA->id, 'order_item_id' => $orderItemA->id], ['qty' => 1, 'amount' => $orderItemA->line_total]);
                        OrderSplitItem::query()->updateOrCreate(['order_split_id' => $splitB->id, 'order_item_id' => $orderItemB->id], ['qty' => 1, 'amount' => $orderItemB->line_total]);
                    }

                    if ($paymentStatus !== 'unpaid') {
                        $method = $i % 6 === 0 ? 'qris' : ($i % 5 === 0 ? 'ewallet' : 'cash');
                        Payment::query()->updateOrCreate(
                            ['order_id' => $order->id, 'method' => $method],
                            ['amount' => $order->paid_total, 'status' => $paymentStatus === 'paid' ? 'paid' : 'pending', 'paid_at' => $paymentStatus === 'paid' ? $orderedAt->addMinutes(8) : null],
                        );

                        $provider = $i % 6 === 0 ? 'midtrans' : ($i % 5 === 0 ? 'xendit' : 'cashier');
                        $txStatus = $status === 'cancelled' ? 'failed' : ($i % 13 === 0 ? 'expired' : ($paymentStatus === 'paid' ? 'paid' : 'pending'));
                        $tx = PaymentTransaction::query()->updateOrCreate(
                            ['provider' => $provider, 'idempotency_key' => "{$outlet->id}-tx-{$i}"],
                            [
                                'order_id' => $order->id,
                                'outlet_id' => $outlet->id,
                                'external_reference' => "{$spec['code']}-TX-{$i}",
                                'amount' => $order->total,
                                'currency' => 'IDR',
                                'status' => $txStatus,
                                'payment_method' => strtoupper($method),
                                'expiry_time' => $orderedAt->addMinutes(30),
                                'paid_at' => $txStatus === 'paid' ? $orderedAt->addMinutes(8) : null,
                                'expired_at' => $txStatus === 'expired' ? $orderedAt->addMinutes(35) : null,
                                'reconciliation_attempts' => $txStatus === 'pending' ? 2 : 1,
                                'last_async_error' => $txStatus === 'pending' ? 'Gateway timeout' : null,
                                'async_retry_after' => $txStatus === 'pending' ? now()->addMinutes(5) : null,
                                'payload_snapshot' => ['order' => $order->code],
                                'provider_metadata_snapshot' => ['channel' => $channel],
                            ],
                        );
                        PaymentTransactionEvent::query()->updateOrCreate(
                            ['payment_transaction_id' => $tx->id, 'event_idempotency_key' => "{$tx->id}-created"],
                            ['event_type' => 'created', 'payload' => ['status' => 'created'], 'created_at' => $orderedAt],
                        );
                        PaymentWebhookReceipt::query()->updateOrCreate(
                            ['provider' => $provider, 'event_idempotency_key' => "{$tx->id}-webhook"],
                            ['external_reference' => $tx->external_reference, 'incoming_status' => $txStatus, 'payload_hash' => sha1((string) $tx->external_reference), 'payload' => ['status' => $txStatus], 'process_attempts' => 1, 'processed_at' => $txStatus === 'paid' ? now() : null, 'next_retry_at' => $txStatus !== 'paid' ? now()->addMinutes(10) : null, 'last_error' => $txStatus !== 'paid' ? 'Awaiting reconcile' : null],
                        );
                    }

                    KitchenTicket::query()->updateOrCreate(
                        ['order_id' => $order->id],
                        [
                            'outlet_id' => $outlet->id,
                            'ticket_no' => "{$spec['code']}-KT-{$i}",
                            'status' => $status === 'completed' ? 'completed' : ($status === 'ready' ? 'ready' : ($status === 'cancelled' ? 'cancelled' : 'queued')),
                            'queued_at' => $orderedAt->addMinutes(1),
                            'started_at' => $status !== 'pending' ? $orderedAt->addMinutes(3) : null,
                            'ready_at' => in_array($status, ['ready', 'completed'], true) ? $orderedAt->addMinutes(6) : null,
                            'served_at' => $status === 'completed' ? $orderedAt->addMinutes(9) : null,
                        ],
                    );
                    $ticket = KitchenTicket::query()->where('order_id', $order->id)->first();
                    KitchenTicketItem::query()->updateOrCreate(
                        ['kitchen_ticket_id' => $ticket->id, 'order_item_id' => $orderItemA->id],
                        ['item_name_snapshot' => $orderItemA->name, 'qty' => 1, 'notes' => null, 'status' => $ticket->status],
                    );
                    KitchenTicketItem::query()->updateOrCreate(
                        ['kitchen_ticket_id' => $ticket->id, 'order_item_id' => $orderItemB->id],
                        ['item_name_snapshot' => $orderItemB->name, 'qty' => 1, 'notes' => $orderItemB->notes, 'status' => $ticket->status],
                    );
                }

                $qrTables = $tables->take(2);
                for ($q = 1; $q <= 12; $q++) {
                    $qrOrder = Order::query()->where('outlet_id', $outlet->id)->where('order_channel', 'qr')->skip($q - 1)->first();
                    if ($qrOrder === null) {
                        continue;
                    }
                    $qrStatus = $q <= 6 ? 'pending_cashier_confirmation' : ($q <= 9 ? 'confirmed' : 'expired');
                    QrOrderRequest::query()->updateOrCreate(
                        ['request_code' => "{$spec['code']}-QR-{$q}"],
                        [
                            'outlet_id' => $outlet->id,
                            'table_id' => $qrTables[($q - 1) % max(1, $qrTables->count())]->id,
                            'customer_name' => "QR Guest {$q}",
                            'status' => $qrStatus,
                            'expires_at' => now()->addMinutes(15),
                            'confirmed_at' => $qrStatus === 'confirmed' ? now()->subMinutes(20) : null,
                            'order_id' => $qrOrder->id,
                            'confirmed_by_user_id' => $qrStatus === 'confirmed' ? $cashiers[0]->id : null,
                            'rejected_at' => null,
                            'rejected_by_user_id' => null,
                            'rejection_reason' => null,
                        ],
                    );
                }
            }
        });
    }

    public static function seedHardware(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $device = HardwareBridgeDevice::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'device_key' => "bridge-{$key}-main"],
                    [
                        'display_label' => "Bridge {$key} Main",
                        'capabilities' => ['lan', 'bluetooth', 'print-queue'],
                        'metadata' => ['watchdog' => ['crashCount' => 1, 'restartCount' => 2, 'stalledSpoolDetected' => $key === 'B'], 'deployment' => ['headless' => true, 'serviceMode' => $key === 'A' ? 'systemd' : 'windows-service'], 'updates' => ['available' => $key === 'B']],
                        'status' => 'active',
                        'last_seen_at' => $key === 'A' ? now()->subMinutes(2) : now()->subMinutes(30),
                        'reconnect_count' => $key === 'A' ? 2 : 7,
                    ],
                );

                $session = HardwareDeviceSession::query()->updateOrCreate(
                    ['session_token' => "demo-session-{$key}"],
                    ['outlet_id' => $outlet->id, 'hardware_bridge_device_id' => $device->id, 'status' => 'open', 'metadata' => ['runtime' => 'stable'], 'opened_at' => self::baseTime()->addDays(15), 'last_heartbeat_at' => now()->subMinutes($key === 'A' ? 1 : 25), 'reconnect_count' => $key === 'A' ? 1 : 4],
                );

                PrinterDeviceProfile::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'printer_code' => "{$key}-bridge-kitchen"],
                    ['hardware_bridge_device_id' => $device->id, 'name' => "Bridge Kitchen {$key}", 'connection_type' => 'bridge', 'status' => $key === 'A' ? 'connected' : 'reconnecting', 'is_enabled' => true, 'last_seen_at' => now()->subMinutes($key === 'A' ? 1 : 20), 'metadata' => ['lan' => ['ip' => $key === 'A' ? '10.10.1.21' : '10.10.2.21'], 'bluetooth' => ['address' => "00:1A:7D:DA:7{$key}:31"]]],
                );

                for ($i = 1; $i <= 6; $i++) {
                    $status = $i <= 2 ? 'acked' : ($i <= 4 ? 'replay_pending' : 'dead_letter');
                    HardwareCommandLog::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'idempotency_key' => "{$outlet->id}-hw-cmd-{$i}"],
                        ['hardware_bridge_device_id' => $device->id, 'hardware_device_session_id' => $session->id, 'command_type' => 'print.dispatch', 'status' => $status, 'payload' => ['job' => $i], 'ack_payload' => $status === 'acked' ? ['ok' => true] : null, 'retry_count' => $status === 'replay_pending' ? 2 : 0, 'next_retry_at' => $status === 'replay_pending' ? now()->addMinutes(5) : null, 'acked_at' => $status === 'acked' ? now()->subMinutes(3) : null, 'dead_lettered_at' => $status === 'dead_letter' ? now()->subMinutes(10) : null, 'last_error_code' => $status === 'dead_letter' ? 'TIMEOUT' : null, 'last_error_message' => $status === 'dead_letter' ? 'Bridge unreachable' : null],
                    );
                }

                HardwareDeviceEvent::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'event_type' => 'heartbeat_received', 'occurred_at' => now()->subMinutes(5)],
                    ['hardware_bridge_device_id' => $device->id, 'hardware_device_session_id' => $session->id, 'payload' => ['runtimeState' => $key === 'A' ? 'healthy' : 'degraded']],
                );
            }
        });
    }

    public static function seedCrmAndLoyalty(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $cashier = User::query()->where('email', 'cashier1@'.strtolower($key).'.demo.resto.local')->first();
                $tiers = [
                    'BRONZE' => LoyaltyMembershipTier::query()->updateOrCreate(['outlet_id' => $outlet->id, 'code' => 'BRONZE'], ['name' => 'Bronze', 'priority' => 1, 'min_lifetime_spend' => 0, 'min_lifetime_visits' => 0, 'points_multiplier' => 1, 'benefits' => ['welcome voucher'], 'is_active' => true]),
                    'SILVER' => LoyaltyMembershipTier::query()->updateOrCreate(['outlet_id' => $outlet->id, 'code' => 'SILVER'], ['name' => 'Silver', 'priority' => 2, 'min_lifetime_spend' => 2000000, 'min_lifetime_visits' => 8, 'points_multiplier' => 1.1, 'benefits' => ['priority queue'], 'is_active' => true]),
                    'GOLD' => LoyaltyMembershipTier::query()->updateOrCreate(['outlet_id' => $outlet->id, 'code' => 'GOLD'], ['name' => 'Gold', 'priority' => 3, 'min_lifetime_spend' => 5000000, 'min_lifetime_visits' => 20, 'points_multiplier' => 1.25, 'benefits' => ['birthday cake'], 'is_active' => true]),
                ];

                for ($i = 1; $i <= 34; $i++) {
                    $isGuest = $i <= 4;
                    $isInactive = $i % 10 === 0;
                    $lifetimeVisits = max(1, ($i * 3) % 24);
                    $lifetimeSpend = $lifetimeVisits * 125000;
                    $tier = $lifetimeSpend >= 5000000 ? $tiers['GOLD'] : ($lifetimeSpend >= 2000000 ? $tiers['SILVER'] : $tiers['BRONZE']);
                    $uuid = self::stableUuid("loyalty-{$key}-{$i}");
                    $account = LoyaltyAccount::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'customer_uuid' => $uuid],
                        ['global_customer_uuid' => self::stableUuid("global-customer-{$key}-{$i}"), 'name' => "Loyalty {$key} {$i}", 'phone' => "0813{$key}".str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'email' => $isGuest ? null : "cust{$i}@".strtolower($key).'.demo.customer', 'points_balance' => $isInactive ? 0 : $lifetimeVisits * 10, 'lifetime_points_earned' => $lifetimeVisits * 20, 'lifetime_points_redeemed' => $lifetimeVisits * 10, 'lifetime_spend' => $lifetimeSpend, 'lifetime_visits' => $lifetimeVisits, 'current_tier_id' => $tier->id, 'last_activity_at' => $isInactive ? now()->subMonths(8) : now()->subDays($i)],
                    );

                    Member::query()->updateOrCreate(
                        ['phone' => "08{$key}99".str_pad((string) $i, 8, '0', STR_PAD_LEFT)],
                        ['name' => $account->name, 'email' => $account->email, 'points' => $account->points_balance, 'status' => $isInactive ? 'inactive' : 'active'],
                    );

                    $ledgerKey = "{$outlet->id}-loyalty-ledger-{$i}";
                    $ledger = LoyaltyPointsLedger::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'idempotency_key' => $ledgerKey],
                        ['loyalty_account_id' => $account->id, 'created_by_user_id' => $cashier?->id, 'transaction_type' => 'accrual', 'reference_type' => 'order', 'reference_id' => (string) $i, 'points_delta' => 20, 'balance_before' => max(0, $account->points_balance - 20), 'balance_after' => $account->points_balance, 'spend_amount' => 120000, 'visit_increment' => 1, 'meta' => ['repeatCustomer' => $lifetimeVisits > 5], 'client_occurred_at' => now()->subDays($i), 'applied_at' => now()->subDays($i)],
                    );

                    if ($i % 6 === 0) {
                        LoyaltyRewardRedemption::query()->updateOrCreate(
                            ['outlet_id' => $outlet->id, 'idempotency_key' => "{$outlet->id}-loyalty-redemption-{$i}"],
                            ['loyalty_account_id' => $account->id, 'created_by_user_id' => $cashier?->id, 'ledger_entry_id' => $ledger->id, 'reward_code' => 'FREE_DRINK', 'points_cost' => 50, 'status' => 'redeemed', 'meta' => ['channel' => 'pos'], 'redeemed_at' => now()->subDays($i - 1)],
                        );
                    }
                }
            }
        });
    }

    public static function seedReplayAndMonitoring(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $terminal = TerminalDevice::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'device_key' => "terminal-{$key}-01"],
                    ['display_label' => "POS Terminal {$key}", 'capabilities' => ['offline-order', 'sync-replay'], 'session_metadata' => ['appVersion' => '1.2.0-demo'], 'status' => 'active', 'last_seen_at' => $key === 'A' ? now()->subMinutes(1) : now()->subHours(4), 'reconnect_count' => $key === 'A' ? 1 : 6],
                );
                for ($i = 1; $i <= 8; $i++) {
                    $status = $i <= 3 ? TerminalSyncOperation::STATUS_APPLIED : ($i <= 5 ? TerminalSyncOperation::STATUS_FAILED : ($i <= 7 ? TerminalSyncOperation::STATUS_REJECTED_STALE : TerminalSyncOperation::STATUS_CONFLICT));
                    $op = TerminalSyncOperation::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'fingerprint' => "{$outlet->id}-sync-fp-{$i}"],
                        ['terminal_device_id' => $terminal->id, 'operation_type' => $i % 2 === 0 ? 'order.upsert' : 'payment.upsert', 'payload' => ['source' => 'offline-cache'], 'status' => $status, 'outcome_summary' => ['result' => $status], 'failure_message' => in_array($status, [TerminalSyncOperation::STATUS_FAILED, TerminalSyncOperation::STATUS_REJECTED_STALE], true) ? 'Out-of-date version' : null, 'conflict_type' => $status === TerminalSyncOperation::STATUS_CONFLICT ? 'version_conflict' : null, 'conflict_detail' => $status === TerminalSyncOperation::STATUS_CONFLICT ? ['serverVersion' => 7, 'clientVersion' => 5] : null, 'duplicate_recommendation' => $i % 4 === 0 ? 'use-latest-version' : null, 'client_occurred_at' => now()->subDays(2)->addMinutes($i * 9), 'server_applied_at' => $status === TerminalSyncOperation::STATUS_APPLIED ? now()->subDays(2)->addMinutes($i * 10) : null, 'duplicate_replay_hits' => $i % 3 === 0 ? 2 : 0],
                    );
                    if ($status === TerminalSyncOperation::STATUS_CONFLICT) {
                        TerminalSyncConflictEvent::query()->updateOrCreate(
                            ['terminal_sync_operation_id' => $op->id],
                            ['outlet_id' => $outlet->id, 'terminal_device_id' => $terminal->id, 'conflict_type' => 'version_conflict', 'recommendation' => 'fetch-latest-and-retry', 'details' => ['op' => $op->operation_type], 'created_at' => now()->subDays(2)],
                        );
                    }
                }

                $orders = Order::query()->where('outlet_id', $outlet->id)->orderBy('id')->limit(30)->get();
                $profile = PrinterProfile::query()->where('outlet_id', $outlet->id)->where('station', 'cashier')->first();
                $route = PrinterRoute::query()->where('outlet_id', $outlet->id)->where('print_type', 'receipt')->first();
                foreach ($orders as $idx => $order) {
                    $state = $idx % 5;
                    $status = $state <= 1 ? 'pending' : ($state === 2 ? 'failed' : 'done');
                    $recovery = $state === 2 ? 'recoverable' : ($state === 4 ? 'dead_letter' : 'none');
                    $job = PrintJob::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'dedupe_key' => "{$outlet->id}-print-{$order->id}"],
                        ['tenant_id' => null, 'type' => $idx % 3 === 0 ? 'kitchen' : 'receipt', 'printer_id' => $profile?->code, 'printer_profile_id' => $profile?->id, 'printer_route_id' => $route?->id, 'source_type' => 'order', 'source_id' => $order->id, 'idempotency_key' => "{$outlet->id}-print-idem-{$order->id}", 'content' => ['order' => $order->code], 'printable_snapshot' => ['lines' => ['Demo print']], 'route_snapshot' => ['station' => $profile?->station], 'status' => $status, 'queued_at' => now()->subMinutes(60 - $idx), 'attempts' => $status === 'failed' ? 3 : 1, 'last_attempt_at' => now()->subMinutes(58 - $idx), 'next_retry_at' => $status === 'failed' ? now()->addMinutes(4) : null, 'retryable' => $status === 'failed', 'failed_at' => $status === 'failed' ? now()->subMinutes(55 - $idx) : null, 'recovery_state' => $recovery, 'last_error' => $status === 'failed' ? 'Printer timeout' : null, 'failure_context' => $status === 'failed' ? ['reason' => 'timeout'] : null, 'processed_at' => $status === 'done' ? now()->subMinutes(30 - $idx) : null],
                    );
                    PrintJobEvent::query()->updateOrCreate(
                        ['print_job_id' => $job->id, 'event_type' => 'demo_seeded'],
                        ['payload' => ['status' => $status], 'created_at' => now()->subMinutes(50 - $idx)],
                    );
                }
            }
        });
    }

    public static function seedGiftCardsAndFiscal(): void
    {
        DB::transaction(function (): void {
            foreach (self::outlets() as $key => $spec) {
                $outlet = Outlet::query()->where('code', $spec['code'])->firstOrFail();
                $issuer = User::query()->where('email', 'manager@'.strtolower($key).'.demo.resto.local')->first();
                $sequence = InvoiceSequence::query()->updateOrCreate(['outlet_id' => $outlet->id, 'series_key' => 'INV'], ['prefix' => "INV-{$key}", 'pad_length' => 6, 'next_value' => 400]);

                for ($i = 1; $i <= 12; $i++) {
                    $issued = 200000 + ($i * 50000);
                    $balance = $i <= 4 ? $issued : ($i <= 8 ? $issued / 2 : 0);
                    $status = $i <= 8 ? 'active' : 'expired';
                    $issuance = GiftCardIssuance::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'code' => "{$spec['code']}-GC-{$i}"],
                        ['issued_by_user_id' => $issuer?->id, 'instrument_type' => 'gift_card', 'issued_amount' => $issued, 'balance_amount' => $balance, 'currency' => 'IDR', 'status' => $status, 'issued_at' => now()->subDays(40 - $i), 'expires_at' => $i <= 8 ? now()->addMonths(5) : now()->subDays(3), 'last_redeemed_at' => $balance < $issued ? now()->subDays(2) : null, 'meta' => ['channel' => 'pos']],
                    );
                    $ledger = GiftCardLedger::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'idempotency_key' => "{$outlet->id}-gc-ledger-{$i}"],
                        ['issuance_id' => $issuance->id, 'created_by_user_id' => $issuer?->id, 'transaction_type' => 'redemption', 'reference_type' => 'order', 'reference_id' => (string) $i, 'amount_delta' => -1 * ($issued - $balance), 'balance_before' => $issued, 'balance_after' => $balance, 'meta' => ['partial' => $balance > 0], 'occurred_at' => now()->subDays(2)],
                    );
                    GiftCardRedemptionSettlement::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'idempotency_key' => "{$outlet->id}-gc-settle-{$i}"],
                        ['issuance_id' => $issuance->id, 'ledger_entry_id' => $ledger->id, 'settlement_reference' => "SETTLE-{$outlet->id}-{$i}", 'payment_transaction_id' => null, 'redeemed_amount' => $issued - $balance, 'status' => $i % 3 === 0 ? 'pending' : 'settled', 'redeemed_at' => now()->subDays(2), 'settled_at' => $i % 3 === 0 ? null : now()->subDay(), 'meta' => ['source' => 'demo']],
                    );
                    GiftCardEvent::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'event_type' => 'gift_card.seeded', 'event_idempotency_key' => "{$outlet->id}-gc-event-{$i}"],
                        ['issuance_id' => $issuance->id, 'payload' => ['status' => $status], 'occurred_at' => now()->subDays(2)],
                    );
                }

                $paidOrders = Order::query()->where('outlet_id', $outlet->id)->where('payment_status', 'paid')->limit(20)->get();
                foreach ($paidOrders as $n => $order) {
                    FiscalInvoice::query()->updateOrCreate(
                        ['outlet_id' => $outlet->id, 'source_type' => 'order', 'source_id' => $order->id],
                        ['fiscal_uuid' => self::stableUuid("fiscal-{$outlet->id}-{$order->id}"), 'invoice_number' => "{$sequence->prefix}-".str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT), 'invoice_sequence_id' => $sequence->id, 'sequence_value' => $n + 1, 'metadata' => ['taxProfile' => 'PPN11'], 'issued_at' => now()->subDays(1)],
                    );
                }
            }
        });
    }

    private static function stableUuid(string $seed): string
    {
        $hex = md5($seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}

