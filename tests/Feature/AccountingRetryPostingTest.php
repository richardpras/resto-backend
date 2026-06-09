<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingRetryPostingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_retry_resolves_failure_and_creates_journal(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Retry Outlet');
        [$cashId, $salesId] = $this->seedPosPostingAccounts((int) $outlet->id);

        $failure = AccountingPostingFailure::query()->create([
            'source_type' => 'order_payment',
            'source_id' => 501,
            'outlet_id' => (int) $outlet->id,
            'error_code' => AccountingPostingFailure::ERROR_MISSING_MAPPING,
            'error_message' => 'Missing mapping',
            'status' => AccountingPostingFailure::STATUS_PENDING,
            'payload_json' => [
                'outlet_id' => (int) $outlet->id,
                'source_type' => 'order_payment',
                'source_id' => 501,
                'journal_date' => now()->toDateString(),
                'description' => 'Retry test',
                'posting_key' => 'order-payment-501',
                'scope' => 'order_payment.501',
                'lines' => [
                    ['account_id' => $cashId, 'debit' => 1000, 'credit' => 0],
                    ['account_id' => $salesId, 'debit' => 0, 'credit' => 1000],
                ],
            ],
        ]);

        $this->postJson('/api/v1/accounting/posting-failures/'.$failure->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', AccountingPostingFailure::STATUS_RESOLVED);

        $this->assertDatabaseHas('journals', ['source_type' => 'order_payment', 'source_id' => 501]);
    }
}
