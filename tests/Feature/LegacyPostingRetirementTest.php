<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LegacyPostingRetirementTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_legacy_payroll_post_journal_returns_gone(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->postJson('/api/v1/payroll/1/post-journal')
            ->assertStatus(410)
            ->assertJsonPath('message', 'Legacy posting retired. Use Payroll V2 Posting.');
    }
}
