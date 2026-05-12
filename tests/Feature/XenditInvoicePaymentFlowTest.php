<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class XenditInvoicePaymentFlowTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['payments.providers.xendit.secret_key' => 'xnd_test_secret']);
        config(['payments.providers.xendit.webhook_token' => 'xnd_test_callback']);
        config(['payments.providers.xendit.qris_callback_url' => 'https://test.local/api/v1/payments/webhooks/xendit']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_invoice_create_webhook_paid_and_duplicate_webhook_is_idempotent(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/qr_codes')) {
                $data = json_decode((string) $request->body(), true);
                $externalId = is_array($data) ? (string) ($data['external_id'] ?? 'ext-missing') : 'ext-missing';

                return Http::response([
                    'id' => 'qr_xdt_flow_1',
                    'external_id' => $externalId,
                    'status' => 'ACTIVE',
                    'type' => 'DYNAMIC',
                    'qr_string' => '000201010212XENDITQRIS'.$externalId,
                    'updated' => now()->addMinutes(15)->toIso8601String(),
                ], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'XDT Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'xdt-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $orderId = $this->createConfirmedOrder((int) $outlet->id, (int) $user->id, 'XDT-FLOW');
        $this->seedAccountingAccounts();

        Passport::actingAs($user);

        $ext = 'ext-xdt-flow-'.uniqid();
        $idem = 'idem-xdt-flow-'.uniqid();

        $init = $this->postJson('/api/v1/payment-transactions', [
            'orderId' => $orderId,
            'outletId' => (int) $outlet->id,
            'provider' => 'xendit',
            'externalReference' => $ext,
            'idempotencyKey' => $idem,
            'amount' => 11000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);
        $init->assertCreated();
        $this->assertSame('', (string) ($init->json('data.checkoutUrl') ?? ''));
        $this->assertStringContainsString('000201010212XENDITQRIS', (string) $init->json('data.qrString'));
        $txId = (int) $init->json('data.id');

        $paymentPayload = [
            'id' => 'qrpy_xdt_flow_1',
            'external_id' => $ext,
            'status' => 'COMPLETED',
            'updated' => now()->toIso8601String(),
            'payment_detail_source' => 'GOPAY',
            'qr_code' => [
                'id' => 'qr_xdt_flow_1',
                'external_id' => $ext,
                'type' => 'DYNAMIC',
            ],
        ];

        $this->postJson('/api/v1/payments/webhooks/xendit', $paymentPayload, [
            'x-callback-token' => 'xnd_test_callback',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(1, DB::table('journals')
            ->where('source_type', 'payment_transaction')
            ->where('source_id', (string) $txId)
            ->count());

        $this->postJson('/api/v1/payments/webhooks/xendit', $paymentPayload, [
            'x-callback-token' => 'xnd_test_callback',
        ])->assertOk();

        $this->assertSame(1, DB::table('journals')
            ->where('source_type', 'payment_transaction')
            ->where('source_id', (string) $txId)
            ->count());
    }

    public function test_xendit_webhook_rejects_invalid_callback_token(): void
    {
        $this->postJson('/api/v1/payments/webhooks/xendit', [
            'id' => 'inv_bad',
            'external_id' => 'missing-tx',
            'status' => 'PAID',
        ], [
            'x-callback-token' => 'wrong',
        ])->assertStatus(422);
    }

    private function seedAccountingAccounts(): void
    {
        DB::table('accounts')->insert([
            [
                'tenant_id' => 1,
                'code' => '1100',
                'name' => 'Cash',
                'type' => 'asset',
                'category' => 'cash_bank',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => 1,
                'code' => '4100',
                'name' => 'Sales',
                'type' => 'revenue',
                'category' => 'sales_revenue',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function createConfirmedOrder(int $outletId, int $openedByUserId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'XDT-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);
        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => $openedByUserId,
            'status' => 'open',
            'opening_cash' => 50000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code.'-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => '201', 'name' => 'Item A', 'qty' => 1, 'price' => 5000],
                ['id' => '202', 'name' => 'Item B', 'qty' => 1, 'price' => 6000],
            ],
            'subtotal' => 11000,
            'tax' => 0,
            'total' => 11000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }
}
