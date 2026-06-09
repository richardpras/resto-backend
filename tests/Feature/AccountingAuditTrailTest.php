<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingAuditTrailTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_health_view_and_settings_change_emit_audit_events(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/health')->assertOk();
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'health_dashboard_viewed']);

        $this->patchJson('/api/v1/accounting/settings', [
            'revenuePostingMode' => 'shift_close',
        ])->assertOk();
        $this->assertDatabaseHas('pos_event_logs', ['event_type' => 'revenue_source_changed']);
    }
}
