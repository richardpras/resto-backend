<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthDashboardTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_health_endpoint_returns_score_and_metrics(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/health')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'failedPostings',
                    'pendingPostings',
                    'duplicatePostingAttempts',
                    'unbalancedJournalAttempts',
                    'missingMappings',
                    'openPeriods',
                    'lockedPeriods',
                    'healthScore',
                ],
            ])
            ->assertJsonPath('data.healthScore', 100);
    }
}
