<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use App\Models\Modules\HR\Domain\EmployeeReimbursementAttachment;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ReimbursementService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeReimbursement>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = EmployeeReimbursement::query()
            ->with(['employee', 'attachments'])
            ->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', (string) $filters['category']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $reimbursementId): EmployeeReimbursement
    {
        $row = EmployeeReimbursement::query()
            ->with(['employee', 'attachments'])
            ->find($reimbursementId);

        abort_if($row === null, Response::HTTP_NOT_FOUND, 'Reimbursement claim not found.');

        $row->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $row->employee);

        return $row;
    }

    public function create(?User $user, array $payload): EmployeeReimbursement
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) ($payload['employeeId'] ?? 0));
        $this->validateClaimFields($payload);

        $row = EmployeeReimbursement::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'claim_no' => 'TEMP',
            'category' => (string) $payload['category'],
            'title' => (string) $payload['title'],
            'description' => $payload['description'] ?? null,
            'claim_amount' => round((float) $payload['claimAmount'], 2),
            'expense_date' => (string) $payload['expenseDate'],
            'status' => EmployeeReimbursement::STATUS_DRAFT,
            'notes' => $payload['notes'] ?? null,
        ]);

        $row->update([
            'claim_no' => sprintf('RMB-%d-%04d', (int) $employee->outlet_id, (int) $row->id),
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function updateDraft(?User $user, int $reimbursementId, array $payload): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft claims can be updated.'],
            ]);
        }

        $data = [];
        if (array_key_exists('category', $payload)) {
            $this->assertCategory((string) $payload['category']);
            $data['category'] = (string) $payload['category'];
        }
        if (array_key_exists('title', $payload)) {
            $data['title'] = (string) $payload['title'];
        }
        if (array_key_exists('description', $payload)) {
            $data['description'] = $payload['description'];
        }
        if (array_key_exists('claimAmount', $payload)) {
            $amount = round((float) $payload['claimAmount'], 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['claimAmount' => ['Claim amount must be positive.']]);
            }
            $data['claim_amount'] = $amount;
        }
        if (array_key_exists('expenseDate', $payload)) {
            $data['expense_date'] = (string) $payload['expenseDate'];
        }
        if (array_key_exists('notes', $payload)) {
            $data['notes'] = $payload['notes'];
        }

        if ($data !== []) {
            $row->update($data);
        }

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function deleteDraft(?User $user, int $reimbursementId): void
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft claims can be deleted.'],
            ]);
        }

        foreach ($row->attachments as $attachment) {
            $this->deleteAttachmentFile($attachment);
        }

        $row->delete();
    }

    public function submit(?User $user, int $reimbursementId): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft claims can be submitted.'],
            ]);
        }

        $row->update([
            'status' => EmployeeReimbursement::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function approve(?User $user, int $reimbursementId, ?string $notes = null): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted claims can be approved.'],
            ]);
        }

        $row->update([
            'status' => EmployeeReimbursement::STATUS_APPROVED,
            'approved_at' => now(),
            'approved_by' => $user?->id,
            'notes' => $notes ?? $row->notes,
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function reject(?User $user, int $reimbursementId, ?string $notes = null): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ['Only submitted claims can be rejected.'],
            ]);
        }

        $row->update([
            'status' => EmployeeReimbursement::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejected_by' => $user?->id,
            'notes' => $notes ?? $row->notes,
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function cancel(?User $user, int $reimbursementId, ?string $notes = null): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if (! in_array($row->status, [EmployeeReimbursement::STATUS_DRAFT, EmployeeReimbursement::STATUS_SUBMITTED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or submitted claims can be cancelled.'],
            ]);
        }

        $row->update([
            'status' => EmployeeReimbursement::STATUS_CANCELLED,
            'notes' => $notes ?? $row->notes,
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function markPaid(?User $user, int $reimbursementId): EmployeeReimbursement
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved claims can be marked paid.'],
            ]);
        }

        $row->update([
            'status' => EmployeeReimbursement::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return $row->refresh()->load(['employee', 'attachments']);
    }

    public function getApplicableAmount(int $employeeId, string $periodEnd): float
    {
        return round((float) $this->applicableQuery($employeeId, $periodEnd)->sum('claim_amount'), 2);
    }

    /**
     * @return array{reimbursementEarning: float, remainingReimbursement: float, claims: Collection<int, EmployeeReimbursement>}
     */
    public function applyForPayrollItem(int $payrollRunItemId, int $employeeId, string $periodEnd): array
    {
        EmployeeReimbursement::query()
            ->where('payroll_run_item_id', $payrollRunItemId)
            ->where('status', EmployeeReimbursement::STATUS_APPROVED)
            ->update(['payroll_run_item_id' => null]);

        $claims = $this->applicableQuery($employeeId, $periodEnd)->get();

        foreach ($claims as $claim) {
            $claim->update(['payroll_run_item_id' => $payrollRunItemId]);
        }

        $earning = round((float) $claims->sum('claim_amount'), 2);
        $remaining = round((float) $this->remainingApprovedAmount($employeeId), 2);

        return [
            'reimbursementEarning' => $earning,
            'remainingReimbursement' => $remaining,
            'claims' => $claims,
        ];
    }

    public function resetForPayrollRun(int $payrollRunId): void
    {
        $itemIds = PayrollRunItemV2::query()
            ->where('payroll_run_id', $payrollRunId)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return;
        }

        EmployeeReimbursement::query()
            ->whereIn('payroll_run_item_id', $itemIds)
            ->where('status', EmployeeReimbursement::STATUS_APPROVED)
            ->update(['payroll_run_item_id' => null]);
    }

    public function markPaidForPayrollRun(int $payrollRunId, ?User $user = null): void
    {
        unset($user);

        $itemIds = PayrollRunItemV2::query()
            ->where('payroll_run_id', $payrollRunId)
            ->pluck('id');

        if ($itemIds->isEmpty()) {
            return;
        }

        EmployeeReimbursement::query()
            ->whereIn('payroll_run_item_id', $itemIds)
            ->where('status', EmployeeReimbursement::STATUS_APPROVED)
            ->update([
                'status' => EmployeeReimbursement::STATUS_PAID,
                'paid_at' => now(),
            ]);
    }

    public function addAttachment(?User $user, int $reimbursementId, UploadedFile $file): EmployeeReimbursementAttachment
    {
        $row = $this->findAccessible($user, $reimbursementId);

        if ($row->status !== EmployeeReimbursement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Attachments can only be added to draft claims.'],
            ]);
        }

        $path = $file->store(
            sprintf('reimbursements/%d/%d', (int) $row->outlet_id, (int) $row->id),
            'local',
        );

        return EmployeeReimbursementAttachment::query()->create([
            'reimbursement_id' => $row->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => (int) $file->getSize(),
            'mime_type' => $file->getClientMimeType(),
            'created_at' => now(),
        ]);
    }

    public function deleteAttachment(?User $user, int $attachmentId): void
    {
        $attachment = EmployeeReimbursementAttachment::query()
            ->with('reimbursement.employee')
            ->find($attachmentId);

        abort_if($attachment === null, Response::HTTP_NOT_FOUND, 'Attachment not found.');

        $reimbursement = $attachment->reimbursement;
        abort_if($reimbursement === null, Response::HTTP_NOT_FOUND, 'Reimbursement claim not found.');

        $reimbursement->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $reimbursement->employee);

        if ($reimbursement->status !== EmployeeReimbursement::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Attachments can only be deleted from draft claims.'],
            ]);
        }

        $this->deleteAttachmentFile($attachment);
        $attachment->delete();
    }

    private function applicableQuery(int $employeeId, string $periodEnd)
    {
        return EmployeeReimbursement::query()
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeReimbursement::STATUS_APPROVED)
            ->whereNull('payroll_run_item_id')
            ->where('approved_at', '<=', $periodEnd.' 23:59:59');
    }

    private function remainingApprovedAmount(int $employeeId): float
    {
        return (float) EmployeeReimbursement::query()
            ->where('employee_id', $employeeId)
            ->where('status', EmployeeReimbursement::STATUS_APPROVED)
            ->whereNull('payroll_run_item_id')
            ->sum('claim_amount');
    }

    private function validateClaimFields(array $payload): void
    {
        $this->assertCategory((string) ($payload['category'] ?? ''));
        if (trim((string) ($payload['title'] ?? '')) === '') {
            throw ValidationException::withMessages(['title' => ['Title is required.']]);
        }
        $amount = round((float) ($payload['claimAmount'] ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['claimAmount' => ['Claim amount must be positive.']]);
        }
        if (empty($payload['expenseDate'])) {
            throw ValidationException::withMessages(['expenseDate' => ['Expense date is required.']]);
        }
    }

    private function assertCategory(string $category): void
    {
        if (! in_array($category, EmployeeReimbursement::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => ['Invalid reimbursement category.']]);
        }
    }

    private function deleteAttachmentFile(EmployeeReimbursementAttachment $attachment): void
    {
        if ($attachment->file_path !== null && Storage::disk('local')->exists($attachment->file_path)) {
            Storage::disk('local')->delete($attachment->file_path);
        }
    }
}
