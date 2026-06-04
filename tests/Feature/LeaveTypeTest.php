<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\LeaveType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class LeaveTypeTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_and_list_leave_types(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        $this->postJson('/api/v1/leave-types', [
            'outletId' => $outlet->id,
            'code' => 'annual_leave',
            'name' => 'Annual Leave',
            'deductLeaveBalance' => true,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'annual_leave');

        $this->getJson('/api/v1/leave-types?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_code_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'sick_leave',
            'name' => 'Sick',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/leave-types', [
            'outletId' => $outlet->id,
            'code' => 'sick_leave',
            'name' => 'Sick Duplicate',
        ])->assertStatus(422);
    }

    public function test_update_deactivates_type(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        $type = LeaveType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'unpaid_leave',
            'name' => 'Unpaid',
            'is_active' => true,
        ]);

        $this->patchJson('/api/v1/leave-types/'.$type->id, ['isActive' => false])
            ->assertOk()
            ->assertJsonPath('data.isActive', false);
    }

    private function seedOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Leave Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lv-out',
        ]);
    }
}
