<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class ReimbursementTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'Rmb Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rmb-out',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-RMB-01',
            'full_name' => 'Rmb Worker',
            'position' => 'Staff',
            'base_salary' => 4000000,
            'status' => 'active',
        ]);
    }

    public function test_create_draft_submit_approve_reject_cancel(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $create = $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'transport',
            'title' => 'Client visit taxi',
            'description' => 'Round trip',
            'claimAmount' => 150000,
            'expenseDate' => '2026-10-15',
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertSame('draft', $create->json('data.status'));
        $this->assertStringContainsString('RMB-', $create->json('data.claimNo'));

        $this->patchJson('/api/v1/reimbursements/'.$id, [
            'claimAmount' => 175000,
        ])->assertOk()
            ->assertJsonPath('data.claimAmount', 175000);

        $this->postJson('/api/v1/reimbursements/'.$id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->patchJson('/api/v1/reimbursements/'.$id, [
            'title' => 'Should fail',
        ])->assertStatus(422);

        $this->postJson('/api/v1/reimbursements/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $rejectId = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'meal',
            'title' => 'Lunch',
            'claimAmount' => 50000,
            'expenseDate' => '2026-10-10',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reimbursements/'.$rejectId.'/submit')->assertOk();
        $this->postJson('/api/v1/reimbursements/'.$rejectId.'/reject', ['notes' => 'No receipt'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $cancelId = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'fuel',
            'title' => 'Fuel claim',
            'claimAmount' => 200000,
            'expenseDate' => '2026-10-12',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reimbursements/'.$cancelId.'/submit')->assertOk();
        $this->postJson('/api/v1/reimbursements/'.$cancelId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_delete_only_draft(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $id = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'other',
            'title' => 'Misc',
            'claimAmount' => 10000,
            'expenseDate' => '2026-10-01',
        ])->assertCreated()->json('data.id');

        $this->deleteJson('/api/v1/reimbursements/'.$id)->assertOk();
        $this->assertNull(EmployeeReimbursement::query()->find($id));

        $id2 = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'other',
            'title' => 'Misc 2',
            'claimAmount' => 20000,
            'expenseDate' => '2026-10-02',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reimbursements/'.$id2.'/submit')->assertOk();
        $this->deleteJson('/api/v1/reimbursements/'.$id2)->assertStatus(422);
    }
}
