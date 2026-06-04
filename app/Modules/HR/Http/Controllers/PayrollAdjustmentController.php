<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\PayrollAdjustment;
use App\Modules\HR\Http\Resources\PayrollAdjustmentResource;
use App\Modules\HR\Services\PayrollAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PayrollAdjustmentController extends Controller
{
    public function __construct(
        private readonly PayrollAdjustmentService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'type' => request()->query('type'),
            'category' => request()->query('category'),
            'periodFrom' => request()->query('periodFrom'),
            'periodTo' => request()->query('periodTo'),
        ]);

        return response()->json([
            'data' => PayrollAdjustmentResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', 'string', Rule::in([PayrollAdjustment::TYPE_EARNING, PayrollAdjustment::TYPE_DEDUCTION])],
            'category' => ['required', 'string', Rule::in(PayrollAdjustment::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'description' => ['nullable', 'string'],
        ]);

        if (empty($validated['effectiveTo'])) {
            $validated['effectiveTo'] = $validated['effectiveFrom'];
        }

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Payroll adjustment created.',
            'data' => new PayrollAdjustmentResource($row),
        ], Response::HTTP_CREATED);
    }

    public function show(int $payrollAdjustment): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $payrollAdjustment);

        return response()->json([
            'data' => new PayrollAdjustmentResource($row),
        ]);
    }

    public function update(int $payrollAdjustment): JsonResponse
    {
        $validated = request()->validate([
            'type' => ['sometimes', 'string', Rule::in([PayrollAdjustment::TYPE_EARNING, PayrollAdjustment::TYPE_DEDUCTION])],
            'category' => ['sometimes', 'string', Rule::in(PayrollAdjustment::CATEGORIES)],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'effectiveFrom' => ['sometimes', 'date'],
            'effectiveTo' => ['sometimes', 'date'],
            'description' => ['nullable', 'string'],
        ]);

        $row = $this->service->update($this->resolveUser(), $payrollAdjustment, $validated);

        return response()->json([
            'message' => 'Payroll adjustment updated.',
            'data' => new PayrollAdjustmentResource($row),
        ]);
    }

    public function approve(int $payrollAdjustment): JsonResponse
    {
        $row = $this->service->approve($this->resolveUser(), $payrollAdjustment);

        return response()->json([
            'message' => 'Payroll adjustment approved.',
            'data' => new PayrollAdjustmentResource($row),
        ]);
    }

    public function cancel(int $payrollAdjustment): JsonResponse
    {
        $row = $this->service->cancel($this->resolveUser(), $payrollAdjustment);

        return response()->json([
            'message' => 'Payroll adjustment cancelled.',
            'data' => new PayrollAdjustmentResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
