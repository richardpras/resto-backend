<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\EmployeeRosterResource;
use App\Modules\HR\Services\EmployeeRosterService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeRosterController extends Controller
{
    public function __construct(
        private readonly EmployeeRosterService $service,
    ) {}

    public function index(): JsonResponse
    {
        $result = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'departmentId' => request()->query('departmentId'),
            'status' => request()->query('status'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => EmployeeRosterResource::collection($result['rows']),
            'meta' => [
                'draftCount' => $result['draftCount'],
                'publishedCount' => $result['publishedCount'],
            ],
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'rosterDate' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Roster entry created successfully.',
            'data' => new EmployeeRosterResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $roster): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['sometimes', 'integer', 'exists:employees,id'],
            'shiftId' => ['nullable', 'integer', 'exists:shifts,id'],
            'rosterDate' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->service->update($this->resolveUser(), $roster, $validated);

        return response()->json([
            'message' => 'Roster entry updated successfully.',
            'data' => new EmployeeRosterResource($row),
        ]);
    }

    public function destroy(int $roster): JsonResponse
    {
        $this->service->delete($this->resolveUser(), $roster);

        return response()->json([
            'message' => 'Roster entry deleted successfully.',
        ]);
    }

    public function generate(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'employeeId' => ['nullable', 'integer', 'exists:employees,id'],
            'departmentId' => ['nullable', 'integer', 'exists:departments,id'],
            'fromDate' => ['required', 'date'],
            'toDate' => ['required', 'date', 'after_or_equal:fromDate'],
            'overwriteExisting' => ['nullable', 'boolean'],
        ]);

        $stats = $this->service->generateFromAssignments($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Roster generation completed.',
            'data' => $stats,
        ]);
    }

    public function copy(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'employeeId' => ['nullable', 'integer', 'exists:employees,id'],
            'sourceFrom' => ['required', 'date'],
            'sourceTo' => ['required', 'date', 'after_or_equal:sourceFrom'],
            'destFrom' => ['required', 'date'],
            'destTo' => ['required', 'date', 'after_or_equal:destFrom'],
        ]);

        $stats = $this->service->copySchedule($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Roster copy completed.',
            'data' => $stats,
        ]);
    }

    public function publish(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'employeeId' => ['nullable', 'integer', 'exists:employees,id'],
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        $stats = $this->service->publish($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Roster entries published.',
            'data' => $stats,
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
