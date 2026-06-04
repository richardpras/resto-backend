<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\EmployeeCashAdvance;
use App\Modules\HR\Http\Resources\EmployeeCashAdvanceInstallmentResource;
use App\Modules\HR\Http\Resources\EmployeeCashAdvanceResource;
use App\Modules\HR\Services\CashAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class CashAdvanceController extends Controller
{
    public function __construct(
        private readonly CashAdvanceService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
        ]);

        return response()->json([
            'data' => EmployeeCashAdvanceResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'repaymentType' => [
                'required',
                'string',
                Rule::in([EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL, EmployeeCashAdvance::REPAYMENT_INSTALLMENT]),
            ],
            'installmentCount' => ['nullable', 'integer', 'min:1', 'max:360'],
            'installmentAmount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $advance = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Cash advance created.',
            'data' => new EmployeeCashAdvanceResource($advance),
        ], Response::HTTP_CREATED);
    }

    public function show(int $cashAdvance): JsonResponse
    {
        $advance = $this->service->findAccessible($this->resolveUser(), $cashAdvance);

        return response()->json([
            'data' => new EmployeeCashAdvanceResource($advance),
        ]);
    }

    public function update(int $cashAdvance): JsonResponse
    {
        $validated = request()->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'repaymentType' => [
                'sometimes',
                'string',
                Rule::in([EmployeeCashAdvance::REPAYMENT_NEXT_PAYROLL, EmployeeCashAdvance::REPAYMENT_INSTALLMENT]),
            ],
            'installmentCount' => ['sometimes', 'integer', 'min:1', 'max:360'],
            'installmentAmount' => ['sometimes', 'numeric', 'min:0.01'],
        ]);

        $advance = $this->service->update($this->resolveUser(), $cashAdvance, $validated);

        return response()->json([
            'message' => 'Cash advance updated.',
            'data' => new EmployeeCashAdvanceResource($advance),
        ]);
    }

    public function approve(int $cashAdvance): JsonResponse
    {
        $advance = $this->service->approve($this->resolveUser(), $cashAdvance);

        return response()->json([
            'message' => 'Cash advance approved.',
            'data' => new EmployeeCashAdvanceResource($advance),
        ]);
    }

    public function activate(int $cashAdvance): JsonResponse
    {
        $advance = $this->service->activate($this->resolveUser(), $cashAdvance);

        return response()->json([
            'message' => 'Cash advance activated.',
            'data' => new EmployeeCashAdvanceResource($advance),
        ]);
    }

    public function cancel(int $cashAdvance): JsonResponse
    {
        $advance = $this->service->cancel($this->resolveUser(), $cashAdvance);

        return response()->json([
            'message' => 'Cash advance cancelled.',
            'data' => new EmployeeCashAdvanceResource($advance),
        ]);
    }

    public function installments(int $cashAdvance): JsonResponse
    {
        $rows = $this->service->installments($this->resolveUser(), $cashAdvance);

        return response()->json([
            'data' => EmployeeCashAdvanceInstallmentResource::collection($rows),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
