<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Http\Requests\AssignRolePermissionsRequest;
use App\Modules\UserManagement\Http\Requests\StoreRoleRequest;
use App\Modules\UserManagement\Http\Resources\RoleResource;
use App\Modules\UserManagement\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends Controller
{
    public function __construct(
        private readonly UserManagementService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => RoleResource::collection($this->service->listRoles()),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->service->createRole($request->validated());

        return response()->json([
            'message' => 'Role created successfully.',
            'data' => new RoleResource($role),
        ], Response::HTTP_CREATED);
    }

    public function assignPermissions(AssignRolePermissionsRequest $request, int $role): JsonResponse
    {
        $updated = $this->service->assignPermissions($role, $request->validated('permissionIds'));
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Role not found');

        return response()->json([
            'message' => 'Role permissions updated successfully.',
            'data' => new RoleResource($updated),
        ]);
    }
}
