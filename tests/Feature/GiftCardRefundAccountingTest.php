<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Accounting\Services\AccountingRefundPostingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\GiftCards\Services\GiftCardAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class GiftCardRefundAccountingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedGiftCardAccounts();
    }

    public function test_refund_restores_balance_and_creates_reversal_journal(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Refund');
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        $orderId = $this->createPaidOrderWithCashJournal((int) $outlet->id, 'GC-RF-ORD', 70000, 70000);
        $settlementId = $this->issueRedeemAndSettle((int) $outlet->id, $orderId, 'GC-RF-001', 30000);

        $balanceBeforeRefund = (float) DB::table('gift_card_issuances')
            ->where('code', 'GC-RF-001')
            ->value('balance_amount');
        $this->assertEquals(20000, $balanceBeforeRefund);

        app(AccountingRefundPostingService::class)->postRefundForOrder($orderId, 70000, (int) $outlet->id, $user);

        $balanceAfterRefund = (float) DB::table('gift_card_issuances')
            ->where('code', 'GC-RF-001')
            ->value('balance_amount');
        $this->assertEquals(50000, $balanceAfterRefund);

        $this->assertDatabaseHas('gift_card_redemption_settlements', [
            'id' => $settlementId,
            'status' => 'reversed',
        ]);

        $this->assertDatabaseHas('gift_card_ledgers', [
            'transaction_type' => 'refund',
            'reference_id' => (string) $orderId,
        ]);

        $settlementJournalId = (int) DB::table('journals')
            ->where('source_type', 'gift_card_settlement')
            ->where('source_id', (string) $settlementId)
            ->value('id');
        $this->assertGreaterThan(0, $settlementJournalId);
        $this->assertNotNull(DB::table('journals')->where('id', $settlementJournalId)->value('reversal_journal_id'));

        $reversalJournalId = (int) DB::table('journals')->where('id', $settlementJournalId)->value('reversal_journal_id');
        $lines = $this->journalLinesByCode($reversalJournalId);
        $this->assertEquals(30000, $lines['4100']['debit'] ?? 0);
        $this->assertEquals(30000, $lines['2130']['credit'] ?? 0);
    }

    public function test_duplicate_refund_retry_does_not_double_restore_balance(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('GC Refund Idemp');
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        $orderId = $this->createPaidOrderWithCashJournal((int) $outlet->id, 'GC-RF-IDEMP', 50000, 50000);
        $this->issueRedeemAndSettle((int) $outlet->id, $orderId, 'GC-RF-IDEMP-C', 20000);

        $service = app(GiftCardAccountingService::class);
        $service->reverseRedemptionForOrder($orderId, (int) $outlet->id, $user, 'refund-test-1');
        $service->reverseRedemptionForOrder($orderId, (int) $outlet->id, $user, 'refund-test-1');

        $balance = (float) DB::table('gift_card_issuances')
            ->where('code', 'GC-RF-IDEMP-C')
            ->value('balance_amount');
        $this->assertEquals(50000, $balance);
        $this->assertEquals(1, DB::table('gift_card_ledgers')->where('transaction_type', 'refund')->count());
        $this->assertEquals(1, DB::table('journals')->where('source_type', 'journal_reversal')->count());
    }

    private function issueRedeemAndSettle(int $outletId, int $orderId, string $code, int $amount): int
    {
        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => $outletId,
            'instrumentType' => 'gift_card',
            'code' => $code,
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-'.$code,
            'cashReceivedAmount' => 50000,
            'paymentMethod' => 'cash',
        ])->assertCreated();

        $redeem = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => $outletId,
            'code' => $code,
            'amount' => $amount,
            'idempotencyKey' => 'redeem-'.$code,
            'referenceType' => 'order',
            'referenceId' => (string) $orderId,
        ])->assertCreated();

        $settlementId = (int) $redeem->json('data.settlement.id');

        $this->postJson('/api/v1/gift-cards/settlements', [
            'outletId' => $outletId,
            'idempotencyKey' => 'settle-'.$code,
            'settlementReference' => 'pos-order#'.$orderId,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$settlementId],
        ])->assertOk();

        return $settlementId;
    }

    private function createPaidOrderWithCashJournal(int $outletId, string $code, int $total, int $paidTotal): int
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'paid_total' => $paidTotal,
            'balance_due' => 0,
            'is_posted' => false,
        ]);

        app(JournalPostingService::class)->postForOrderPayment(
            (int) $order->id,
            1,
            $outletId,
            (float) $paidTotal,
            0.0,
        );

        return (int) $order->id;
    }

    private function seedGiftCardAccounts(): void
    {
        foreach ([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'category' => 'cash_bank'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'category' => 'gift_card_liability'],
            ['code' => '4100', 'name' => 'Sales', 'type' => 'revenue', 'category' => 'sales_revenue'],
        ] as $row) {
            if (DB::table('accounts')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'tenant_id' => 1,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'category' => $row['category'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function journalLinesByCode(int $journalId): array
    {
        $rows = DB::table('journal_entries as je')
            ->join('accounts as a', 'a.id', '=', 'je.account_id')
            ->where('je.journal_id', $journalId)
            ->get(['a.code', 'je.debit', 'je.credit']);

        $lines = [];
        foreach ($rows as $row) {
            $lines[(string) $row->code] = [
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
            ];
        }

        return $lines;
    }
}
