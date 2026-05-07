<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingPeriod;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingPeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    private \App\Models\User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->user = $this->actingAsUserManagementApiAdministrator();
    }

    public function test_overlapping_periods_rejected_and_close_open_lifecycle_works(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'P9 Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p9-'.uniqid(),
        ]);
        $this->assignUserToOutlets($this->user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/accounting-periods', [
            'name' => 'May 2026',
            'outletId' => (int) $outlet->id,
            'startDate' => '2026-05-01',
            'endDate' => '2026-05-31',
        ]);
        $create->assertCreated();
        $periodId = (int) $create->json('data.id');

        $overlap = $this->postJson('/api/v1/accounting-periods', [
            'name' => 'Overlap',
            'outletId' => (int) $outlet->id,
            'startDate' => '2026-05-15',
            'endDate' => '2026-06-15',
        ]);
        $overlap->assertUnprocessable();

        $close = $this->postJson("/api/v1/accounting-periods/{$periodId}/close");
        $close->assertOk()->assertJsonPath('data.status', 'closed');

        $open = $this->postJson("/api/v1/accounting-periods/{$periodId}/open");
        $open->assertOk()->assertJsonPath('data.status', 'open');

        $list = $this->getJson('/api/v1/accounting-periods');
        $list->assertOk()->assertJsonPath('success', true);
    }
}
