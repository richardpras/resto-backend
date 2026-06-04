<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeLoanInstallmentResource;
use App\Modules\HR\Http\Resources\EmployeeLoanResource;
use App\Modules\HR\Services\EmployeeLoanService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeLoanController extends Controller
{
    public function __construct(
        private readonly EmployeeLoanService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
        ]);

        return response()->json([
            'data' => EmployeeLoanResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'principalAmount' => ['required', 'numeric', 'min:0.01'],
            'installmentAmount' => ['required', 'numeric', 'min:0.01'],
            'totalInstallments' => ['required', 'integer', 'min:1', 'max:360'],
        ]);

        $loan = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Employee loan created.',
            'data' => new EmployeeLoanResource($loan),
        ], Response::HTTP_CREATED);
    }

    public function show(int $employeeLoan): JsonResponse
    {
        $loan = $this->service->findAccessible($this->resolveUser(), $employeeLoan);

        return response()->json([
            'data' => new EmployeeLoanResource($loan),
        ]);
    }

    public function update(int $employeeLoan): JsonResponse
    {
        $validated = request()->validate([
            'principalAmount' => ['sometimes', 'numeric', 'min:0.01'],
            'installmentAmount' => ['sometimes', 'numeric', 'min:0.01'],
            'totalInstallments' => ['sometimes', 'integer', 'min:1', 'max:360'],
        ]);

        $loan = $this->service->update($this->resolveUser(), $employeeLoan, $validated);

        return response()->json([
            'message' => 'Employee loan updated.',
            'data' => new EmployeeLoanResource($loan),
        ]);
    }

    public function approve(int $employeeLoan): JsonResponse
    {
        $loan = $this->service->approve($this->resolveUser(), $employeeLoan);

        return response()->json([
            'message' => 'Employee loan approved.',
            'data' => new EmployeeLoanResource($loan),
        ]);
    }

    public function activate(int $employeeLoan): JsonResponse
    {
        $loan = $this->service->activate($this->resolveUser(), $employeeLoan);

        return response()->json([
            'message' => 'Employee loan activated.',
            'data' => new EmployeeLoanResource($loan),
        ]);
    }

    public function cancel(int $employeeLoan): JsonResponse
    {
        $loan = $this->service->cancel($this->resolveUser(), $employeeLoan);

        return response()->json([
            'message' => 'Employee loan cancelled.',
            'data' => new EmployeeLoanResource($loan),
        ]);
    }

    public function installments(int $employeeLoan): JsonResponse
    {
        $rows = $this->service->installments($this->resolveUser(), $employeeLoan);

        return response()->json([
            'data' => EmployeeLoanInstallmentResource::collection($rows),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
