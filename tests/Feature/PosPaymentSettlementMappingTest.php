<?php

namespace Tests\Feature;

use App\Modules\GiftCards\Services\GiftCardAccountingService;
use App\Modules\GiftCards\Support\GiftCardRedemptionComposition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\TestCase;

class PosPaymentSettlementMappingTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;

    public function test_qris_settlement_uses_pos_payment_qris_mapping(): void
    {
        $this->seedPosPostingAccountsAndMappings();

        $service = app(GiftCardAccountingService::class);
        $lines = $service->buildSalesJournalLinesFromPayments(
            ['qris' => 75000],
            new GiftCardRedemptionComposition,
            1,
        );

        $qrisAccountId = (int) DB::table('accounts')->where('code', '1120')->value('id');
        $debitLine = collect($lines)->first(fn (array $line): bool => (float) $line['debit'] > 0);

        $this->assertNotNull($debitLine);
        $this->assertSame($qrisAccountId, (int) $debitLine['account_id']);
    }

    public function test_payment_method_override_takes_precedence_over_settlement_default(): void
    {
        $this->seedPosPostingAccountsAndMappings(null, ['manual_qris' => '1110']);

        $overrideAccountId = (int) DB::table('accounting_posting_mappings')
            ->whereNull('outlet_id')
            ->where('rule_key', 'pos.payment.manual_qris')
            ->value('chart_account_id');
        $this->assertGreaterThan(0, $overrideAccountId);

        $service = app(GiftCardAccountingService::class);
        $lines = $service->buildSalesJournalLinesFromPayments(
            ['manual_qris' => 50000],
            new GiftCardRedemptionComposition,
            1,
        );

        $debitLine = collect($lines)->first(fn (array $line): bool => (float) $line['debit'] > 0);

        $this->assertNotNull($debitLine);
        $this->assertSame($overrideAccountId, (int) $debitLine['account_id']);
    }
}
