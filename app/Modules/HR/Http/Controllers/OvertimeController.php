<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Overtime;
use App\Modules\HR\Http\Resources\OvertimeResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OvertimeController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Overtime::query()->latest('id')->get();

        return response()->json([
            'data' => OvertimeResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:pending,approved,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = Overtime::query()->create([
            'employee_id' => $validated['employeeId'],
            'date' => $validated['date'],
            'hours' => $validated['hours'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => request()->user()?->id,
            'updated_by' => request()->user()?->id,
        ]);

        return response()->json([
            'message' => 'Overtime created successfully.',
            'data' => new OvertimeResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $overtime): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['sometimes', 'integer', 'exists:employees,id'],
            'date' => ['sometimes', 'date'],
            'hours' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = Overtime::query()->findOrFail($overtime);
        $row->fill([
            'employee_id' => $validated['employeeId'] ?? $row->employee_id,
            'date' => $validated['date'] ?? $row->date,
            'hours' => $validated['hours'] ?? $row->hours,
            'status' => $validated['status'] ?? $row->status,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $row->notes,
            'updated_by' => request()->user()?->id,
        ])->save();

        return response()->json([
            'message' => 'Overtime updated successfully.',
            'data' => new OvertimeResource($row->refresh()),
        ]);
    }

    public function destroy(int $overtime): JsonResponse
    {
        $row = Overtime::query()->findOrFail($overtime);
        $row->delete();

        return response()->json([
            'message' => 'Overtime deleted successfully.',
        ]);
    }
}
