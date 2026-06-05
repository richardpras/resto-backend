<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ClosedPayrollRunFixture;
use Tests\Concerns\HrmApiFixture;
use Tests\Concerns\PayrollPostingAccountsFixture;
use Tests\TestCase;

class PayrollPostingJournalTest extends TestCase
{
    use ClosedPayrollRunFixture;
    use HrmApiFixture;
    use PayrollPostingAccountsFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedPayrollPostingAccounts();
    }

    public function test_journal_balanced_with_correct_liability_accounts(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedClosedPayrollRun();
        $runId = (int) $run->id;

        $preview = $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/posting-preview')->assertOk();
        $debit = (float) $preview->json('data.totals.debit');
        $credit = (float) $preview->json('data.totals.credit');
        $this->assertEquals($debit, $credit);
        $this->assertGreaterThan(0, $debit);

        $codes = collect($preview->json('data.lines'))->pluck('accountCode')->all();
        $this->assertContains('6100', $codes);
        $this->assertContains('2150', $codes);

        $post = $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/post')->assertCreated();
        $journalId = (int) $post->json('data.journalEntryId');

        $entries = JournalEntry::query()->where('journal_id', $journalId)->get();
        $entryDebit = round((float) $entries->sum('debit'), 2);
        $entryCredit = round((float) $entries->sum('credit'), 2);
        $this->assertEquals($entryDebit, $entryCredit);
        $this->assertEquals($debit, $entryDebit);
    }
}
