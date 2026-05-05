<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Loan;
use App\Modules\HR\Http\Resources\LoanResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LoanController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Loan::query()->latest('id')->get();

        return response()->json([
            'data' => LoanResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'installments' => ['required', 'integer', 'min:1'],
            'paidInstallments' => ['nullable', 'integer', 'min:0'],
            'startDate' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $installments = (int) $validated['installments'];
        $paidInstallments = (int) ($validated['paidInstallments'] ?? 0);
        abort_if(
            $paidInstallments > $installments,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'paidInstallments must be less than or equal to installments.'
        );
        $status = $paidInstallments >= $installments ? 'completed' : 'active';

        $row = Loan::query()->create([
            'employee_id' => $validated['employeeId'],
            'amount' => $validated['amount'],
            'installments' => $installments,
            'paid_installments' => $paidInstallments,
            'start_date' => $validated['startDate'],
            'notes' => $validated['notes'] ?? null,
            'status' => $status,
            'created_by' => request()->user()?->id,
            'updated_by' => request()->user()?->id,
        ]);

        return response()->json([
            'message' => 'Loan created successfully.',
            'data' => new LoanResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $loan): JsonResponse
    {
        $row = Loan::query()->findOrFail($loan);

        $validated = request()->validate([
            'employeeId' => ['sometimes', 'integer', 'exists:employees,id'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'installments' => ['sometimes', 'integer', 'min:1'],
            'paidInstallments' => ['sometimes', 'integer', 'min:0'],
            'startDate' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $installments = (int) ($validated['installments'] ?? $row->installments);
        $paidInstallments = (int) ($validated['paidInstallments'] ?? $row->paid_installments);
        abort_if(
            $paidInstallments > $installments,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'paidInstallments must be less than or equal to installments.'
        );
        $status = $paidInstallments >= $installments ? 'completed' : 'active';

        $row->fill([
            'employee_id' => $validated['employeeId'] ?? $row->employee_id,
            'amount' => $validated['amount'] ?? $row->amount,
            'installments' => $installments,
            'paid_installments' => $paidInstallments,
            'start_date' => $validated['startDate'] ?? $row->start_date,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $row->notes,
            'status' => $status,
            'updated_by' => request()->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Loan updated successfully.',
            'data' => new LoanResource($row->refresh()),
        ]);
    }

    public function destroy(int $loan): JsonResponse
    {
        $row = Loan::query()->findOrFail($loan);
        $row->delete();

        return response()->json([
            'message' => 'Loan deleted successfully.',
        ]);
    }
}
