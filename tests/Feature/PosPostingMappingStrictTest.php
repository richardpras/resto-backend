<?php

namespace Tests\Feature;

use App\Modules\GiftCards\Services\GiftCardAccountingService;
use App\Modules\GiftCards\Support\GiftCardRedemptionComposition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\TestCase;

class PosPostingMappingStrictTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;

    public function test_order_payment_journal_fails_without_mappings(): void
    {
        $this->seedPosPostingAccountsAndMappings();

        DB::table('accounting_posting_mappings')->where('module', 'pos')->delete();

        $service = app(GiftCardAccountingService::class);

        $this->expectException(UnprocessableEntityHttpException::class);
        $service->postOrderPaymentJournal(1, 1, 1, 50000, new GiftCardRedemptionComposition, 0);
    }

    public function test_order_payment_journal_uses_mapped_revenue_and_cash(): void
    {
        $this->seedPosPostingAccountsAndMappings();

        $service = app(GiftCardAccountingService::class);
        $journal = $service->postOrderPaymentJournal(1, 1, 1, 50000, new GiftCardRedemptionComposition, 0);

        $this->assertNotNull($journal);
        $revenueAccountId = (int) DB::table('accounts')->where('code', '4100')->value('id');
        $cashAccountId = (int) DB::table('accounts')->where('code', '1100')->value('id');

        $lines = DB::table('journal_entries')->where('journal_id', $journal->id)->get();
        $accountIds = $lines->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($revenueAccountId, $accountIds);
        $this->assertContains($cashAccountId, $accountIds);
    }
}
