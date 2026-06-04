<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\LeaveRequestResource;
use App\Modules\HR\Services\LeaveRequestService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'leaveTypeId' => request()->query('leaveTypeId'),
            'fromDate' => request()->query('fromDate'),
            'toDate' => request()->query('toDate'),
        ]);

        return response()->json([
            'data' => LeaveRequestResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'leaveTypeId' => ['required', 'integer', 'exists:leave_types,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'reason' => ['nullable', 'string'],
            'attachmentPath' => ['nullable', 'string', 'max:500'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Leave request submitted.',
            'data' => new LeaveRequestResource($row->load(['employee', 'leaveType'])),
        ], Response::HTTP_CREATED);
    }

    public function show(int $leaveRequest): JsonResponse
    {
        $row = $this->service->findAccessible($this->resolveUser(), $leaveRequest);

        return response()->json([
            'data' => new LeaveRequestResource($row),
        ]);
    }

    public function approve(int $leaveRequest): JsonResponse
    {
        $row = $this->service->approve($this->resolveUser(), $leaveRequest);

        return response()->json([
            'message' => 'Leave request approved.',
            'data' => new LeaveRequestResource($row),
        ]);
    }

    public function reject(int $leaveRequest): JsonResponse
    {
        $validated = request()->validate([
            'rejectionReason' => ['nullable', 'string'],
        ]);

        $row = $this->service->reject($this->resolveUser(), $leaveRequest, $validated);

        return response()->json([
            'message' => 'Leave request rejected.',
            'data' => new LeaveRequestResource($row),
        ]);
    }

    public function cancel(int $leaveRequest): JsonResponse
    {
        $row = $this->service->cancel($this->resolveUser(), $leaveRequest);

        return response()->json([
            'message' => 'Leave request cancelled.',
            'data' => new LeaveRequestResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
