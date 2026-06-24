<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class GiftCardIssueAccountingTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedAccounts();
    }

    public function test_issue_with_payment_creates_liability_journal(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $response = $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-ISSUE-001',
            'initialAmount' => 50000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-acc-1',
            'cashReceivedAmount' => 50000,
            'paymentMethod' => 'cash',
            'paymentReference' => 'POS-RCPT-001',
        ])->assertCreated();

        $issuanceId = (int) $response->json('data.issuance.id');
        $this->assertEquals('posted', $response->json('data.issuance.meta.accountingStatus'));

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'gift_card_issue')
            ->where('source_id', (string) $issuanceId)
            ->value('id');

        $this->assertGreaterThan(0, $journalId);
        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(50000, $lines['1100']['debit'] ?? 0);
        $this->assertEquals(50000, $lines['2130']['credit'] ?? 0);
    }

    public function test_store_credit_issue_uses_2135_liability(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $response = $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'store_credit',
            'code' => 'SC-ISSUE-001',
            'initialAmount' => 25000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-sc-acc-1',
            'cashReceivedAmount' => 25000,
            'paymentMethod' => 'cash',
        ])->assertCreated();

        $journalId = (int) DB::table('journals')
            ->where('source_type', 'gift_card_issue')
            ->where('source_id', (string) $response->json('data.issuance.id'))
            ->value('id');

        $lines = $this->journalLinesByCode($journalId);
        $this->assertEquals(25000, $lines['2135']['credit'] ?? 0);
    }

    public function test_duplicate_issue_idempotency_does_not_duplicate_journal(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();
        $payload = [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-ISSUE-IDEMP',
            'initialAmount' => 40000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-idemp-acc',
            'cashReceivedAmount' => 40000,
            'paymentMethod' => 'cash',
        ];

        $this->postJson('/api/v1/gift-cards/issue', $payload)->assertCreated();
        $this->postJson('/api/v1/gift-cards/issue', $payload)->assertCreated()
            ->assertJsonPath('data.idempotent', true);

        $this->assertEquals(1, DB::table('journals')->where('source_type', 'gift_card_issue')->count());
    }

    public function test_issue_without_payment_marks_pending_gl(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $response = $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-NO-PAY',
            'initialAmount' => 30000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-no-pay',
        ])->assertCreated();

        $this->assertEquals('pending_gl', $response->json('data.issuance.meta.accountingStatus'));
        $this->assertEquals(0, DB::table('journals')->where('source_type', 'gift_card_issue')->count());
    }

    /** @return array{0:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'GC Issue Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gc-issue-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$outlet];
    }

    private function seedAccounts(): void
    {
        $this->seedPosPostingAccountsAndMappings();
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
