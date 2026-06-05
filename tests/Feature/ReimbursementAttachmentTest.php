<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeReimbursementAttachment;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class ReimbursementAttachmentTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('local');
    }

    private function seedEmployee(): Employee
    {
        $outlet = Outlet::query()->create([
            'name' => 'Rmb Att Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rmb-att',
        ]);

        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-RMB-ATT',
            'full_name' => 'Attachment Worker',
            'position' => 'Staff',
            'base_salary' => 3000000,
            'status' => 'active',
        ]);
    }

    public function test_upload_and_delete_attachment_on_draft(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $claimId = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'purchase',
            'title' => 'Office supplies',
            'claimAmount' => 75000,
            'expenseDate' => '2026-10-05',
        ])->assertCreated()->json('data.id');

        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

        $upload = $this->postJson('/api/v1/reimbursements/'.$claimId.'/attachments', [
            'file' => $file,
        ])->assertCreated();

        $attachmentId = (int) $upload->json('data.id');
        $this->assertSame('receipt.pdf', $upload->json('data.fileName'));

        $attachment = EmployeeReimbursementAttachment::query()->findOrFail($attachmentId);
        Storage::disk('local')->assertExists($attachment->file_path);

        $this->deleteJson('/api/v1/reimbursements/attachments/'.$attachmentId)->assertOk();
        $this->assertNull(EmployeeReimbursementAttachment::query()->find($attachmentId));
        Storage::disk('local')->assertMissing($attachment->file_path);
    }

    public function test_cannot_upload_after_submit(): void
    {
        $this->actingAsHrmApiAdministrator();
        $employee = $this->seedEmployee();

        $claimId = (int) $this->postJson('/api/v1/reimbursements', [
            'employeeId' => $employee->id,
            'category' => 'medical',
            'title' => 'Clinic',
            'claimAmount' => 120000,
            'expenseDate' => '2026-10-08',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/reimbursements/'.$claimId.'/submit')->assertOk();

        $file = UploadedFile::fake()->image('receipt.jpg');
        $this->postJson('/api/v1/reimbursements/'.$claimId.'/attachments', [
            'file' => $file,
        ])->assertStatus(422);
    }
}
