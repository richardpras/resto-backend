<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashFlowStatementTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_cash_flow_report_includes_activity_sections(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/reports/cash-flow')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'operating',
                    'investing',
                    'financing',
                    'netCashChange',
                    'period',
                    'from',
                    'to',
                ],
            ]);
    }
}
