<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingReversalIntegrityTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->actingAsUserManagementApiAdministrator();
    }

    public function test_reversal_creates_immutable_inverse_and_prevents_double_reversal(): void
    {
        [$cashId, $salesId] = $this->seedAccounts();
        $postedId = $this->createPostedJournal($cashId, $salesId, 1000);

        $reverse = $this->postJson("/api/v1/journals/{$postedId}/reverse", [
            'reason' => 'Correction',
            'postingKey' => 'rev-1',
        ]);
        $reverse->assertOk();
        $reverse->assertJsonPath('success', true);
        $reversalId = (int) $reverse->json('data.id');

        $this->assertDatabaseHas('journals', [
            'id' => $postedId,
            'reversal_journal_id' => $reversalId,
            'immutable' => true,
        ]);
        $this->assertDatabaseHas('journals', [
            'id' => $reversalId,
            'reversal_of_journal_id' => $postedId,
            'immutable' => true,
        ]);

        $dup = $this->postJson("/api/v1/journals/{$postedId}/reverse");
        $dup->assertUnprocessable();
    }

    public function test_idempotent_reversal_retry_returns_same_reversal_and_period_lock_rejects(): void
    {
        [$cashId, $salesId] = $this->seedAccounts();
        $postedId = $this->createPostedJournal($cashId, $salesId, 500);

        $first = $this->postJson("/api/v1/journals/{$postedId}/reverse", ['postingKey' => 'rev-idem-1']);
        $first->assertOk();
        $reversalId = (int) $first->json('data.id');
        $retry = $this->postJson("/api/v1/journals/{$postedId}/reverse", ['postingKey' => 'rev-idem-1']);
        $retry->assertOk()->assertJsonPath('data.id', (string) $reversalId);

        $postedId2 = $this->createPostedJournal($cashId, $salesId, 250, now()->subDay()->format('Y-m-d'));
        DB::table('accounting_periods')->insert([
            'tenant_id' => 1,
            'outlet_id' => null,
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locked = $this->postJson("/api/v1/journals/{$postedId2}/reverse", ['postingKey' => 'rev-locked']);
        $locked->assertUnprocessable();
    }

    /** @return array{0:int,1:int} */
    private function seedAccounts(): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'code' => '1100'.random_int(100, 999),
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'code' => '4100'.random_int(100, 999),
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }

    private function createPostedJournal(int $cashId, int $salesId, float $amount, ?string $journalDate = null): int
    {
        $resp = $this->postJson('/api/v1/journals', [
            'tenantId' => 1,
            'journalDate' => $journalDate ?? now()->format('Y-m-d'),
            'status' => 'posted',
            'postingKey' => 'post-'.uniqid(),
            'lines' => [
                ['accountId' => $cashId, 'debit' => $amount, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => $amount],
            ],
        ]);
        $resp->assertCreated();

        return (int) $resp->json('data.id');
    }
}
