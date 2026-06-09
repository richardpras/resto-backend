<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Accounting\Services\AccountingRefundPostingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingRefundPostingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_refund_reverses_original_payment_journal(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Refund Outlet');
        [$cashId, $salesId] = $this->seedPosPostingAccounts((int) $outlet->id);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'REF-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 5000,
            'tax' => 0,
            'total' => 5000,
        ]);

        $transaction = PaymentTransaction::query()->create([
            'order_id' => (int) $order->id,
            'outlet_id' => (int) $outlet->id,
            'provider' => 'cash',
            'external_reference' => 'ref-test-'.uniqid(),
            'idempotency_key' => 'refund-test-'.uniqid(),
            'amount' => 5000,
            'currency' => 'IDR',
            'status' => 'refunded',
            'payment_method' => 'cash',
        ]);

        $journal = app(JournalPostingService::class)->post([
            'outlet_id' => (int) $outlet->id,
            'source_type' => 'payment_transaction',
            'source_id' => (string) $transaction->id,
            'journal_date' => now()->toDateString(),
            'posting_key' => 'payment-transaction-'.$transaction->id,
            'scope' => 'payment_transaction.'.$transaction->id,
            'lines' => [
                ['account_id' => $cashId, 'debit' => 5000, 'credit' => 0],
                ['account_id' => $salesId, 'debit' => 0, 'credit' => 5000],
            ],
        ]);

        $reversal = app(AccountingRefundPostingService::class)->postRefundForPaymentTransaction($transaction, null);
        $this->assertNotNull($reversal);
        $journal->refresh();
        $this->assertNotNull($journal->reversal_journal_id);
        $this->assertDatabaseHas('journals', ['source_type' => 'journal_reversal']);
    }
}
