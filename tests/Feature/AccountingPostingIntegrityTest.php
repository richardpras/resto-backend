<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingPostingIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Integrity Outlet');
        [$cashId, $salesId] = $this->seedPosPostingAccounts((int) $outlet->id);

        $this->expectException(UnprocessableEntityHttpException::class);
        app(JournalPostingService::class)->post([
            'outlet_id' => (int) $outlet->id,
            'journal_date' => now()->toDateString(),
            'source_type' => 'manual',
            'source_id' => 999,
            'posting_key' => 'integrity-unbalanced',
            'scope' => 'manual.test',
            'lines' => [
                ['account_id' => $cashId, 'debit' => 100, 'credit' => 0],
                ['account_id' => $salesId, 'debit' => 0, 'credit' => 50],
            ],
        ]);
    }

    public function test_balanced_journal_passes_integrity_checks(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Integrity Balanced');
        [$cashId, $salesId] = $this->seedPosPostingAccounts((int) $outlet->id);

        $journal = app(JournalPostingService::class)->post([
            'outlet_id' => (int) $outlet->id,
            'journal_date' => now()->toDateString(),
            'source_type' => 'manual',
            'source_id' => 999,
            'posting_key' => 'integrity-balanced',
            'scope' => 'manual.test2',
            'lines' => [
                ['account_id' => $cashId, 'debit' => 100, 'credit' => 0],
                ['account_id' => $salesId, 'debit' => 0, 'credit' => 100],
            ],
        ]);

        $this->assertSame('posted', $journal->status);
    }
}
