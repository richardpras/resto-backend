<?php

namespace Tests\Feature;

use App\Modules\Accounting\Services\AccountingHealthService;
use App\Modules\Inventory\Services\InventoryValuationReconciliationService;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryAccountingTieOutTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_valuation_reconciliation_reports_balanced_when_gl_matches(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        app(InventoryValuationService::class)->recordPurchase((int) $ingredient->id, (int) $outlet->id, 10, 10000);

        $accountId = DB::table('accounts')->insertGetId([
            'code' => '1300',
            'name' => 'Inventory',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'category' => 'inventory',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalId = DB::table('journals')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'journal_no' => 'J-VAL-1',
            'journal_date' => now()->toDateString(),
            'description' => 'Test inventory',
            'status' => 'posted',
            'source_type' => 'test',
            'source_id' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_entries')->insert([
            ['journal_id' => $journalId, 'account_id' => $accountId, 'line_no' => 1, 'debit' => 100000, 'credit' => 0, 'memo' => 'inv', 'created_at' => now(), 'updated_at' => now()],
            ['journal_id' => $journalId, 'account_id' => $accountId, 'line_no' => 2, 'debit' => 0, 'credit' => 0, 'memo' => 'bal', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $report = app(InventoryValuationReconciliationService::class)->report(null, (int) $outlet->id);
        $this->assertSame(100000.0, (float) $report['inventoryValuationBalance']);
        $this->assertSame('balanced', $report['status']);

        $health = app(AccountingHealthService::class)->report(null, (int) $outlet->id);
        $this->assertArrayHasKey('inventoryValuationStatus', $health);
        $this->assertSame('balanced', $health['inventoryValuationStatus']);
    }
}
