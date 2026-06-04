<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_account_crud_and_balanced_journal_lifecycle(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $a1 = $this->postJson('/api/v1/accounts', [
            'code' => '1100',
            'name' => 'Cash',
            'type' => 'asset',
            'subtype' => 'current_asset',
            'active' => true,
        ]);
        $a1->assertCreated();
        $id1 = (int) $a1->json('data.id');

        $a2 = $this->postJson('/api/v1/accounts', [
            'code' => '4100',
            'name' => 'Sales Revenue',
            'type' => 'revenue',
            'subtype' => 'revenue',
            'active' => true,
        ]);
        $a2->assertCreated();
        $id2 = (int) $a2->json('data.id');

        $list = $this->getJson('/api/v1/accounts');
        $list->assertOk();
        $list->assertJsonCount(2, 'data');

        $journal = $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'description' => 'Test sale',
            'outlet' => 'Main Outlet',
            'status' => 'draft',
            'lines' => [
                ['accountId' => $id1, 'debit' => 100000, 'credit' => 0],
                ['accountId' => $id2, 'debit' => 0, 'credit' => 100000],
            ],
        ]);
        $journal->assertCreated();
        $jid = (int) $journal->json('data.id');

        $this->postJson("/api/v1/journals/{$jid}/post")->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->postJson('/api/v1/journals', [
            'journalDate' => now()->format('Y-m-d'),
            'description' => 'Unbalanced',
            'lines' => [
                ['accountId' => $id1, 'debit' => 1000, 'credit' => 0],
                ['accountId' => $id2, 'debit' => 0, 'credit' => 500],
            ],
        ])->assertStatus(422);
    }
}
