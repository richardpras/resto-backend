<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingReversalAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_outlet_scoped_user_cannot_reverse_out_of_scope_journal(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->makeOutlet('Allowed');
        $forbiddenOutlet = $this->makeOutlet('Forbidden');
        $this->assignUserToOutlets($user, [$allowedOutlet->id]);

        [$cashId, $salesId] = $this->seedAccounts((int) $forbiddenOutlet->id);
        $journalId = (int) \Illuminate\Support\Facades\DB::table('journals')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => (int) $forbiddenOutlet->id,
            'journal_no' => 'AUTH-JRN-'.uniqid(),
            'source_type' => 'manual',
            'journal_date' => now()->toDateString(),
            'status' => 'posted',
            'immutable' => true,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('journal_entries')->insert([
            [
                'journal_id' => $journalId,
                'account_id' => $cashId,
                'debit' => 100,
                'credit' => 0,
                'line_no' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'journal_id' => $journalId,
                'account_id' => $salesId,
                'debit' => 0,
                'credit' => 100,
                'line_no' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $reject = $this->postJson("/api/v1/journals/{$journalId}/reverse");
        $reject->assertNotFound();
        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'unauthorized_access_attempt',
            'entity_type' => 'journal',
            'entity_id' => $journalId,
        ]);
    }

    private function makeOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name.' '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($name).'-'.uniqid(),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function seedAccounts(int $outletId): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '11'.random_int(1000, 9999),
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'scope' => 'outlet',
            'code' => '41'.random_int(1000, 9999),
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }
}
