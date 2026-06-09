<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Services\AccountingVoidPostingService;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosVoidPostingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_void_payment_creates_reversal_journal(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Void POS');
        $this->seedPosPostingAccounts((int) $outlet->id);

        $orderId = 7;
        $journal = app(JournalPostingService::class)->postForOrderPayment($orderId, 1, (int) $outlet->id, 5000, 0);
        $this->assertNotNull($journal);

        $reversal = app(AccountingVoidPostingService::class)->voidPosOrderPayment($orderId, (int) $outlet->id);
        $this->assertNotNull($reversal);

        $journal->refresh();
        $this->assertSame((int) $reversal->id, (int) $journal->reversal_journal_id);
        $this->assertDatabaseHas('journals', [
            'id' => (int) $reversal->id,
            'source_type' => 'journal_reversal',
        ]);
    }
}
