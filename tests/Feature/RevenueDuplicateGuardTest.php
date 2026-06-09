<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Services\RevenuePostingGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class RevenueDuplicateGuardTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_guard_prevents_second_revenue_journal_for_same_order(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Revenue Guard');
        $this->seedPosPostingAccounts((int) $outlet->id);
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        $orderId = 42;
        $svc = app(JournalPostingService::class);
        $first = $svc->postForOrderPayment($orderId, 1, (int) $outlet->id, 10000, 0);
        $this->assertNotNull($first);

        $second = $svc->postForOrderPayment($orderId, 1, (int) $outlet->id, 10000, 0);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, Journal::query()->where('source_type', 'order_payment')->where('source_id', (string) $orderId)->count());

        $blocked = app(RevenuePostingGuardService::class)->shouldSkipDuplicate(
            $orderId,
            'payment_transaction',
            '999',
            (int) $outlet->id,
        );
        $this->assertNotNull($blocked);
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'revenue_duplicate_prevented']);
    }
}
