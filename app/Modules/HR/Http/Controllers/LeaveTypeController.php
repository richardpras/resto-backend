<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\LeaveTypeResource;
use App\Modules\HR\Services\LeaveTypeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly LeaveTypeService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'isActive' => request()->query('isActive'),
        ]);

        return response()->json([
            'data' => LeaveTypeResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'requiresAttachment' => ['nullable', 'boolean'],
            'deductLeaveBalance' => ['nullable', 'boolean'],
            'paidLeave' => ['nullable', 'boolean'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Leave type created.',
            'data' => new LeaveTypeResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $leaveType): JsonResponse
    {
        $validated = request()->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'requiresAttachment' => ['nullable', 'boolean'],
            'deductLeaveBalance' => ['nullable', 'boolean'],
            'paidLeave' => ['nullable', 'boolean'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $row = $this->service->update($this->resolveUser(), $leaveType, $validated);

        return response()->json([
            'message' => 'Leave type updated.',
            'data' => new LeaveTypeResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
