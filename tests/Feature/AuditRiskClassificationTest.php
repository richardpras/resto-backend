<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\CriticalAuditNotificationAdapter;
use App\Modules\System\DTO\UnifiedAuditRecord;
use App\Modules\System\Services\AuditRiskClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AuditRiskClassificationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_classifies_critical_journal_reversal(): void
    {
        $service = app(AuditRiskClassificationService::class);

        $this->assertSame(
            AuditRiskClassificationService::RISK_CRITICAL,
            $service->classify('accounting', 'journal', 'reversal_created'),
        );
    }

    public function test_classifies_warning_purchase_approval(): void
    {
        $service = app(AuditRiskClassificationService::class);

        $this->assertSame(
            AuditRiskClassificationService::RISK_WARNING,
            $service->classify('purchase', 'purchase_order', 'purchase_order_approved'),
        );
    }

    public function test_classifies_info_dashboard_view(): void
    {
        $service = app(AuditRiskClassificationService::class);

        $this->assertSame(
            AuditRiskClassificationService::RISK_INFO,
            $service->classify('menu', 'dashboard_snapshot', 'dashboard_viewed'),
        );
    }

    public function test_critical_audit_notification_persisted(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = \App\Models\Modules\Settings\Domain\Outlet::query()->create([
            'name' => 'Risk Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'risk-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $record = new UnifiedAuditRecord(
            'pos:1',
            'accounting',
            'journal',
            10,
            'reversal_created',
            (int) $user->id,
            $user->name,
            (int) $outlet->id,
            now()->toIso8601String(),
            [],
            [],
            ['riskLevel' => AuditRiskClassificationService::RISK_CRITICAL],
        );

        app(CriticalAuditNotificationAdapter::class)->notifyCriticalEvent((int) $outlet->id, $record);

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_SYSTEM,
            'source_type' => CriticalAuditNotificationAdapter::TYPE_CRITICAL_AUDIT_EVENT,
            'action_url' => '/system/audit',
        ]);
    }
}
