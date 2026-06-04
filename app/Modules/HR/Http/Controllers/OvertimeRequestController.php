<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\OvertimeRequestResource;
use App\Modules\HR\Services\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OvertimeRequestController extends Controller
{
    public function __construct(
        private readonly OvertimeRequestService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => OvertimeRequestResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'overtimeTypeId' => ['required', 'integer', 'exists:overtime_types,id'],
            'overtimeDate' => ['required', 'date'],
            'startTime' => ['required', 'string'],
            'endTime' => ['required', 'string'],
            'reason' => ['nullable', 'string'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Overtime request submitted.',
            'data' => new OvertimeRequestResource($row->load(['employee', 'overtimeType'])),
        ], Response::HTTP_CREATED);
    }

    public function show(int $overtimeRequest): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $overtimeRequest);

        return response()->json([
            'data' => new OvertimeRequestResource($row),
        ]);
    }

    public function approve(int $overtimeRequest): JsonResponse
    {
        $row = $this->service->approve($this->resolveUser(), $overtimeRequest);

        return response()->json([
            'message' => 'Overtime request approved.',
            'data' => new OvertimeRequestResource($row),
        ]);
    }

    public function reject(int $overtimeRequest): JsonResponse
    {
        $validated = request()->validate([
            'rejectionReason' => ['nullable', 'string'],
        ]);

        $row = $this->service->reject($this->resolveUser(), $overtimeRequest, $validated);

        return response()->json([
            'message' => 'Overtime request rejected.',
            'data' => new OvertimeRequestResource($row),
        ]);
    }

    public function cancel(int $overtimeRequest): JsonResponse
    {
        $row = $this->service->cancel($this->resolveUser(), $overtimeRequest);

        return response()->json([
            'message' => 'Overtime request cancelled.',
            'data' => new OvertimeRequestResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
