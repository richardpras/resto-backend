<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ExecutiveSalesReportApiTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_executive_sales_requires_reports_view_permission(): void
    {
        $this->getJson('/api/v1/reports/executive-sales')->assertUnauthorized();
    }

    public function test_gross_sales_and_net_sales_exclude_gift_card_from_discounts(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Exec Sales');
        $date = now()->format('Y-m-d');

        $orderId = $this->seedPaidOrder($outlet->id, [
            'code' => 'ES-1',
            'subtotal' => 100000,
            'discount_amount' => 10000,
            'total' => 90000,
            'paid_total' => 90000,
            'source' => 'pos',
            'order_channel' => 'dine_in',
        ]);

        $this->attachVoucherDiscount($orderId, 10000);

        DB::table('payments')->insert([
            'order_id' => $orderId,
            'method' => 'cash',
            'amount' => 60000,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedGiftCardSettlement($outlet->id, $orderId, 30000, 'gift_card', 'settled');

        $response = $this->getJson('/api/v1/reports/executive-sales?'.http_build_query([
            'outletId' => $outlet->id,
            'startDate' => $date,
            'endDate' => $date,
        ]))->assertOk();

        $response->assertJsonPath('data.summary.grossSales', 100000);
        $response->assertJsonPath('data.summary.voucherDiscount', 10000);
        $response->assertJsonPath('data.summary.netSales', 90000);
        $response->assertJsonPath('data.summary.giftCardSalesSettled', 30000);
        $response->assertJsonPath('data.summary.finalRevenue', 90000);

        $payments = collect($response->json('data.payments'));
        $this->assertSame(30000.0, (float) $payments->firstWhere('method', 'gift_card')['amount']);
        $this->assertSame(60000.0, (float) $payments->firstWhere('method', 'cash')['amount']);
    }

    public function test_channel_and_payment_aggregation(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Exec Channel');
        $date = now()->format('Y-m-d');

        $posOrder = $this->seedPaidOrder($outlet->id, [
            'code' => 'ES-POS',
            'subtotal' => 50000,
            'discount_amount' => 0,
            'total' => 50000,
            'paid_total' => 50000,
            'source' => 'pos',
            'order_channel' => 'dine_in',
        ]);
        DB::table('payments')->insert([
            'order_id' => $posOrder,
            'method' => 'qris',
            'amount' => 50000,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $qrOrder = $this->seedPaidOrder($outlet->id, [
            'code' => 'ES-QR',
            'subtotal' => 30000,
            'discount_amount' => 0,
            'total' => 30000,
            'paid_total' => 30000,
            'source' => 'qr',
            'order_channel' => 'qr',
        ]);
        DB::table('payments')->insert([
            'order_id' => $qrOrder,
            'method' => 'cash',
            'amount' => 30000,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reports/executive-sales?'.http_build_query([
            'outletId' => $outlet->id,
            'startDate' => $date,
            'endDate' => $date,
        ]))->assertOk();

        $channels = collect($response->json('data.channels'));
        $this->assertSame(50000.0, (float) $channels->firstWhere('channel', 'pos')['sales']);
        $this->assertSame(30000.0, (float) $channels->firstWhere('channel', 'qr_ordering')['sales']);
        $this->assertSame(2, (int) $response->json('data.summary.orderCount'));
    }

    public function test_refund_amount_is_reported(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Exec Refund');
        $date = now()->format('Y-m-d');

        $orderId = $this->seedPaidOrder($outlet->id, [
            'code' => 'ES-REF',
            'subtotal' => 25000,
            'discount_amount' => 0,
            'total' => 25000,
            'paid_total' => 25000,
        ]);

        DB::table('payment_transactions')->insert([
            'outlet_id' => $outlet->id,
            'order_id' => $orderId,
            'provider' => 'manual',
            'external_reference' => 'ref-1',
            'idempotency_key' => 'refund-1',
            'amount' => 25000,
            'currency' => 'IDR',
            'status' => 'refunded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/reports/executive-sales?'.http_build_query([
            'outletId' => $outlet->id,
            'startDate' => $date,
            'endDate' => $date,
        ]))->assertOk();

        $response->assertJsonPath('data.summary.refundAmount', 25000);
        $response->assertJsonPath('data.summary.refundCount', 1);
    }

    public function test_accounting_reconciliation_when_accounting_manage_present(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Exec Recon');
        $date = now()->format('Y-m-d');
        [$cashId, $salesId] = $this->seedRevenueAccounts((int) $outlet->id);

        $this->seedPaidOrder($outlet->id, [
            'code' => 'ES-RECON',
            'subtotal' => 150000,
            'discount_amount' => 0,
            'total' => 150000,
            'paid_total' => 150000,
        ]);

        $this->postJson('/api/v1/journals', [
            'tenantId' => 1,
            'outletId' => (int) $outlet->id,
            'journalDate' => $date,
            'status' => 'posted',
            'postingKey' => 'exec-sales-recon',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 150000, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 150000],
            ],
        ])->assertCreated();

        $response = $this->getJson('/api/v1/reports/executive-sales?'.http_build_query([
            'outletId' => $outlet->id,
            'startDate' => $date,
            'endDate' => $date,
        ]))->assertOk();

        $this->assertArrayHasKey('accountingReconciliation', $response->json('data.summary'));
        $this->assertSame(150000.0, (float) $response->json('data.summary.accountingReconciliation.accountingRevenue'));
        $this->assertSame(150000.0, (float) $response->json('data.summary.accountingReconciliation.executiveRevenue'));
        $response->assertJsonPath('data.summary.accountingReconciliation.status', 'balanced');
    }

    /** @param  array<string,mixed>  $overrides */
    private function seedPaidOrder(int $outletId, array $overrides = []): int
    {
        return (int) DB::table('orders')->insertGetId(array_merge([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => 'ORD-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 100000,
            'tax' => 0,
            'total' => 100000,
            'discount_amount' => 0,
            'paid_total' => 100000,
            'balance_due' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedGiftCardSettlement(int $outletId, int $orderId, float $amount, string $instrument, string $status): void
    {
        $issuanceId = DB::table('gift_card_issuances')->insertGetId([
            'outlet_id' => $outletId,
            'instrument_type' => $instrument,
            'code' => 'GC-'.uniqid(),
            'issued_amount' => $amount,
            'balance_amount' => 0,
            'status' => 'active',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ledgerId = DB::table('gift_card_ledgers')->insertGetId([
            'issuance_id' => $issuanceId,
            'outlet_id' => $outletId,
            'transaction_type' => 'redeem',
            'idempotency_key' => 'ledger-'.uniqid(),
            'reference_type' => 'order',
            'reference_id' => (string) $orderId,
            'amount_delta' => -$amount,
            'balance_before' => $amount,
            'balance_after' => 0,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('gift_card_redemption_settlements')->insert([
            'issuance_id' => $issuanceId,
            'ledger_entry_id' => $ledgerId,
            'outlet_id' => $outletId,
            'idempotency_key' => 'settle-'.uniqid(),
            'redeemed_amount' => $amount,
            'status' => $status,
            'settled_at' => now(),
            'redeemed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachVoucherDiscount(int $orderId, float $amount): void
    {
        $outletId = (int) DB::table('orders')->where('id', $orderId)->value('outlet_id');

        $voucherId = DB::table('loyalty_vouchers')->insertGetId([
            'outlet_id' => $outletId,
            'code' => 'SAVE10-'.uniqid(),
            'name' => 'Save 10',
            'voucher_type' => 'manual',
            'value_type' => 'fixed',
            'value' => $amount,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $memberId = DB::table('members')->insertGetId([
            'name' => 'Voucher Member',
            'phone' => '0812'.random_int(10000000, 99999999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $memberVoucherId = DB::table('member_vouchers')->insertGetId([
            'outlet_id' => $outletId,
            'member_id' => $memberId,
            'voucher_id' => $voucherId,
            'voucher_code' => 'MV-'.uniqid(),
            'status' => 'redeemed',
            'issued_at' => now(),
            'redeemed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_vouchers')->insert([
            'order_id' => $orderId,
            'member_voucher_id' => $memberVoucherId,
            'voucher_id' => $voucherId,
            'voucher_code' => 'SAVE10',
            'discount_type' => 'fixed',
            'discount_value' => $amount,
            'discount_amount' => $amount,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function seedRevenueAccounts(int $outletId): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '4100',
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }
}
