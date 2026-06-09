<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingRevenueSourceLockTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_realtime_mode_skips_shift_close_revenue_posting(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Revenue Realtime');
        $this->seedPosPostingAccounts((int) $outlet->id);
        $this->setRevenuePostingMode(AccountingSetting::MODE_REALTIME, (int) $outlet->id);

        app(JournalPostingService::class)->postForOrderPayment(1, 1, (int) $outlet->id, 10000, 0);

        $close = app(\App\Modules\Orders\Services\OrderService::class)->closeShiftAndPostJournal(1, (int) $outlet->id);
        $this->assertTrue($close['skipped'] ?? false);
        $this->assertDatabaseMissing('journals', ['source_type' => 'shift_close']);
    }

    public function test_shift_close_mode_skips_realtime_order_payment_journal(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Revenue Shift');
        $this->seedPosPostingAccounts((int) $outlet->id);
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);

        app(JournalPostingService::class)->postForOrderPayment(2, 1, (int) $outlet->id, 10000, 0);

        $this->assertDatabaseMissing('journals', ['source_type' => 'order_payment', 'source_id' => 2]);
    }

    public function test_settings_api_updates_revenue_mode(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->patchJson('/api/v1/accounting/settings', [
            'revenuePostingMode' => 'shift_close',
        ])->assertOk()->assertJsonPath('data.revenuePostingMode', 'shift_close');
    }
}
