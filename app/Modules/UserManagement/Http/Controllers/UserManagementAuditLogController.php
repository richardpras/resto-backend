<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Http\Requests\ListUserManagementAuditLogsRequest;
use App\Modules\UserManagement\Http\Resources\UserManagementAuditLogResource;
use App\Modules\UserManagement\Services\UserManagementAuditService;
use Illuminate\Http\JsonResponse;

class UserManagementAuditLogController extends Controller
{
    public function __construct(
        private readonly UserManagementAuditService $auditService,
    ) {}

    public function index(ListUserManagementAuditLogsRequest $request): JsonResponse
    {
        $paginator = $this->auditService->list($request->validated(), $request->user());

        return response()->json([
            'data' => UserManagementAuditLogResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
