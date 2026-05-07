<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingPeriodPostingLockTest extends TestCase
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

    public function test_posting_and_reversal_into_closed_period_are_rejected(): void
    {
        [$cashId, $salesId] = $this->seedAccounts();

        $lockedMay = $this->postJson('/api/v1/accounting-periods', [
            'name' => 'Locked May',
            'startDate' => '2026-05-01',
            'endDate' => '2026-05-31',
        ]);
        $lockedMay->assertCreated();
        $lockedMayId = (int) $lockedMay->json('data.id');
        $this->postJson("/api/v1/accounting-periods/{$lockedMayId}/close")->assertOk();

        $draft = $this->postJson('/api/v1/journals', [
            'journalDate' => '2026-05-10',
            'status' => 'draft',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 100, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 100],
            ],
        ]);
        $draft->assertCreated();
        $draftId = (int) $draft->json('data.id');

        $blockedPost = $this->postJson("/api/v1/journals/{$draftId}/post");
        $blockedPost->assertUnprocessable();

        $posted = $this->postJson('/api/v1/journals', [
            'journalDate' => '2026-06-10',
            'status' => 'posted',
            'postingKey' => 'p91-post-1',
            'lines' => [
                ['accountId' => $cashId, 'debit' => 200, 'credit' => 0],
                ['accountId' => $salesId, 'debit' => 0, 'credit' => 200],
            ],
        ]);
        $posted->assertCreated();
        $postedId = (int) $posted->json('data.id');

        $lockedJune = $this->postJson('/api/v1/accounting-periods', [
            'name' => 'Lock June',
            'startDate' => '2026-06-01',
            'endDate' => '2026-06-30',
        ]);
        $lockedJune->assertCreated();
        $lockedJuneId = (int) $lockedJune->json('data.id');
        $this->postJson("/api/v1/accounting-periods/{$lockedJuneId}/close")->assertOk();

        $blockedReverse = $this->postJson("/api/v1/journals/{$postedId}/reverse", [
            'postingKey' => 'p91-rev-1',
        ]);
        $blockedReverse->assertUnprocessable();
    }

    /** @return array{0:int,1:int} */
    private function seedAccounts(): array
    {
        $cash = Account::query()->create([
            'tenant_id' => 1,
            'code' => '11'.random_int(1000, 9999),
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'is_active' => true,
        ]);
        $sales = Account::query()->create([
            'tenant_id' => 1,
            'code' => '41'.random_int(1000, 9999),
            'name' => 'Sales',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'is_active' => true,
        ]);

        return [(int) $cash->id, (int) $sales->id];
    }
}
