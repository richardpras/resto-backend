<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Accounting\Domain\JournalPostingKey;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ShiftClosePostingIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_shift_close_posts_journal_with_posting_key(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Shift Close');
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);
        $this->seedGlobalShiftCloseAccounts();

        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'SHIFT-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 15000,
            'total' => 15000,
            'subtotal' => 15000,
            'tax' => 0,
            'is_posted' => false,
        ]);

        $result = app(OrderService::class)->closeShiftAndPostJournal(1, (int) $outlet->id);
        $this->assertSame(1, (int) ($result['orderCount'] ?? 0));
        $journalId = (int) ($result['journalId'] ?? 0);
        $this->assertGreaterThan(0, $journalId);

        $this->assertDatabaseHas('journals', [
            'id' => $journalId,
            'source_type' => 'shift_close',
            'status' => 'posted',
        ]);
        $this->assertTrue(
            JournalPostingKey::query()->where('journal_id', $journalId)->where('idempotency_key', 'like', 'shift-close-%')->exists()
        );
    }

    private function seedGlobalShiftCloseAccounts(): void
    {
        DB::table('accounts')->insert([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}