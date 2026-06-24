<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingPostingMappingsFixture;
use Tests\TestCase;

class InventoryPostingMappingStrictTest extends TestCase
{
    use AccountingPostingMappingsFixture;
    use RefreshDatabase;

    public function test_inventory_movement_posting_fails_without_mappings(): void
    {
        $this->seedInventoryPostingAccountsAndMappings();

        DB::table('accounting_posting_mappings')->where('module', 'inventory')->delete();

        $service = app(JournalPostingService::class);
        $journal = $service->postForInventoryMovement('waste', 1, 1, 1, 10000.0);

        $this->assertNull($journal);
        $this->assertDatabaseHas('accounting_posting_failures', [
            'source_type' => 'inventory_waste',
            'source_id' => '1',
        ]);
    }

    public function test_inventory_waste_posting_uses_mapped_accounts(): void
    {
        $this->seedInventoryPostingAccountsAndMappings();

        $service = app(JournalPostingService::class);
        $journal = $service->postForInventoryMovement('waste', 42, 1, 1, 15000.0);

        $this->assertNotNull($journal);
        $inventoryId = (int) DB::table('accounts')->where('code', '1300')->value('id');
        $wasteId = (int) DB::table('accounts')->where('code', '5200')->value('id');

        $lines = DB::table('journal_entries')->where('journal_id', $journal->id)->get();
        $accountIds = $lines->pluck('account_id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($inventoryId, $accountIds);
        $this->assertContains($wasteId, $accountIds);
    }

    public function test_inventory_adjustment_posting_uses_mapped_accounts(): void
    {
        $this->seedInventoryPostingAccountsAndMappings();

        $service = app(JournalPostingService::class);
        $journal = $service->postForInventoryMovement('adjustment', 7, 1, 1, 8000.0);

        $this->assertNotNull($journal);
        $adjustmentId = (int) DB::table('accounts')->where('code', '5300')->value('id');
        $accountIds = DB::table('journal_entries')
            ->where('journal_id', $journal->id)
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($adjustmentId, $accountIds);
    }
}
