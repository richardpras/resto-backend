<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingHealthSnapshot;
use App\Modules\Accounting\Services\AccountingHealthSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthSnapshotTest extends TestCase
{
    use AccountingRemediationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_snapshot_command_persists_daily_row(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Snapshot Outlet');

        $this->artisan('accounting:health-snapshot', ['--outletId' => (int) $outlet->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('accounting_health_snapshots', [
            'outlet_id' => (int) $outlet->id,
            'severity' => 'healthy',
        ]);
    }

    public function test_snapshot_service_is_idempotent_per_day(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Snapshot Idempotent');

        $service = app(AccountingHealthSnapshotService::class);
        $first = $service->captureForOutlet((int) $outlet->id);
        $second = $service->captureForOutlet((int) $outlet->id);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, AccountingHealthSnapshot::query()->where('outlet_id', (int) $outlet->id)->count());
    }
}
