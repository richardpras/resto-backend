<?php

namespace Tests\Feature;

use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\GiftCards\Services\GiftCardAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\TestCase;

class GiftCardIssueExpiryMappingStrictTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;

    public function test_issue_liability_skips_without_mappings(): void
    {
        $this->seedPosPostingAccountsAndMappings();
        DB::table('accounting_posting_mappings')->where('module', 'pos')->delete();

        $issuance = $this->createIssuance('gift_card');
        $journal = app(GiftCardAccountingService::class)->postIssueLiability($issuance, 50000.0, 'cash');

        $this->assertNull($journal);
    }

    public function test_issue_liability_uses_pos_gift_card_mappings(): void
    {
        $this->seedPosPostingAccountsAndMappings();

        $issuance = $this->createIssuance('gift_card');
        $journal = app(GiftCardAccountingService::class)->postIssueLiability($issuance, 50000.0, 'cash');

        $this->assertNotNull($journal);
        $cashId = (int) DB::table('accounts')->where('code', '1100')->value('id');
        $liabilityId = (int) DB::table('accounts')->where('code', '2130')->value('id');
        $lines = DB::table('journal_entries')->where('journal_id', $journal->id)->get();
        $accountIds = $lines->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($cashId, $accountIds);
        $this->assertContains($liabilityId, $accountIds);
    }

    public function test_expiry_breakage_fails_without_mappings(): void
    {
        $this->seedPosPostingAccountsAndMappings();
        DB::table('accounting_posting_mappings')->where('module', 'pos')->delete();

        $issuance = $this->createIssuance('gift_card');

        $this->expectException(UnprocessableEntityHttpException::class);
        app(GiftCardAccountingService::class)->postExpiryBreakage($issuance, 10000.0);
    }

    public function test_expiry_breakage_uses_redemption_liability_and_breakage_revenue(): void
    {
        $this->seedPosPostingAccountsAndMappings();

        $issuance = $this->createIssuance('store_credit');
        $journal = app(GiftCardAccountingService::class)->postExpiryBreakage($issuance, 12000.0);

        $this->assertNotNull($journal);
        $liabilityId = (int) DB::table('accounts')->where('code', '2135')->value('id');
        $breakageId = (int) DB::table('accounts')->where('code', '4190')->value('id');
        $lines = DB::table('journal_entries')->where('journal_id', $journal->id)->get();
        $accountIds = $lines->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($liabilityId, $accountIds);
        $this->assertContains($breakageId, $accountIds);
    }

    private function createIssuance(string $instrumentType): GiftCardIssuance
    {
        $outlet = Outlet::query()->create([
            'name' => 'GC Strict Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gc-strict-'.uniqid(),
        ]);

        return GiftCardIssuance::query()->create([
            'outlet_id' => (int) $outlet->id,
            'instrument_type' => $instrumentType,
            'code' => 'GC-STRICT-'.uniqid(),
            'issued_amount' => 50000,
            'balance_amount' => 50000,
            'currency' => 'IDR',
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);
    }
}
