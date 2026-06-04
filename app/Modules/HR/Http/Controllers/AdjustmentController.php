<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Adjustment;
use App\Modules\HR\Http\Resources\AdjustmentResource;
use App\Modules\HR\Services\EmployeeMasterService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdjustmentController extends Controller
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    public function index(): JsonResponse
    {
        $query = Adjustment::query()->latest('id');
        $rows = $this->employeeMaster->scopeByEmployeeOutlet($query, $this->resolveUser())->get();

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

        $this->employeeMaster->findAccessible($this->resolveUser(), (int) $validated['employeeId']);

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
        $this->employeeMaster->findAccessible($this->resolveUser(), (int) $row->employee_id);
        $row->delete();

        return response()->json([
            'message' => 'Adjustment deleted successfully.',
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
