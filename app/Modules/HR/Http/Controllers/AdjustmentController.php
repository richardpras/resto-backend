<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Adjustment;
use App\Modules\HR\Http\Resources\AdjustmentResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdjustmentController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Adjustment::query()->latest('id')->get();

        return response()->json([
            'data' => AdjustmentResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', 'in:allowance,deduction'],
            'category' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = Adjustment::query()->create([
            'employee_id' => $validated['employeeId'],
            'type' => $validated['type'],
            'category' => $validated['category'],
            'amount' => $validated['amount'],
            'date' => $validated['date'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => request()->user()?->id,
            'updated_by' => request()->user()?->id,
        ]);

        return response()->json([
            'message' => 'Adjustment created successfully.',
            'data' => new AdjustmentResource($row),
        ], Response::HTTP_CREATED);
    }

    public function destroy(int $adjustment): JsonResponse
    {
        $row = Adjustment::query()->findOrFail($adjustment);
        $row->delete();

        return response()->json([
            'message' => 'Adjustment deleted successfully.',
        ]);
    }
}
