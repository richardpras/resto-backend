<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\EmployeeReimbursement;
use App\Modules\HR\Http\Resources\EmployeeReimbursementAttachmentResource;
use App\Modules\HR\Http\Resources\EmployeeReimbursementResource;
use App\Modules\HR\Services\ReimbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReimbursementController extends Controller
{
    public function __construct(
        private readonly ReimbursementService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'category' => request()->query('category'),
        ]);

        return response()->json([
            'data' => EmployeeReimbursementResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'category' => ['required', 'string', Rule::in(EmployeeReimbursement::CATEGORIES)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'claimAmount' => ['required', 'numeric', 'min:0.01'],
            'expenseDate' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Reimbursement claim created.',
            'data' => new EmployeeReimbursementResource($row),
        ], Response::HTTP_CREATED);
    }

    public function show(int $reimbursement): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $reimbursement);

        return response()->json([
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function update(int $reimbursement): JsonResponse
    {
        $validated = request()->validate([
            'category' => ['sometimes', 'string', Rule::in(EmployeeReimbursement::CATEGORIES)],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'claimAmount' => ['sometimes', 'numeric', 'min:0.01'],
            'expenseDate' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->updateDraft($this->resolveUser(), $reimbursement, $validated);

        return response()->json([
            'message' => 'Reimbursement claim updated.',
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function destroy(int $reimbursement): JsonResponse
    {
        $this->service->deleteDraft($this->resolveUser(), $reimbursement);

        return response()->json([
            'message' => 'Reimbursement claim deleted.',
        ]);
    }

    public function submit(int $reimbursement): JsonResponse
    {
        $row = $this->service->submit($this->resolveUser(), $reimbursement);

        return response()->json([
            'message' => 'Reimbursement claim submitted.',
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function approve(int $reimbursement): JsonResponse
    {
        $validated = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->approve($this->resolveUser(), $reimbursement, $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Reimbursement claim approved.',
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function reject(int $reimbursement): JsonResponse
    {
        $validated = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->reject($this->resolveUser(), $reimbursement, $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Reimbursement claim rejected.',
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function cancel(int $reimbursement): JsonResponse
    {
        $validated = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->cancel($this->resolveUser(), $reimbursement, $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Reimbursement claim cancelled.',
            'data' => new EmployeeReimbursementResource($row),
        ]);
    }

    public function storeAttachment(int $reimbursement): JsonResponse
    {
        $validated = request()->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $attachment = $this->service->addAttachment(
            $this->resolveUser(),
            $reimbursement,
            $validated['file'],
        );

        return response()->json([
            'message' => 'Attachment uploaded.',
            'data' => new EmployeeReimbursementAttachmentResource($attachment),
        ], Response::HTTP_CREATED);
    }

    public function destroyAttachment(int $attachment): JsonResponse
    {
        $this->service->deleteAttachment($this->resolveUser(), $attachment);

        return response()->json([
            'message' => 'Attachment deleted.',
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
