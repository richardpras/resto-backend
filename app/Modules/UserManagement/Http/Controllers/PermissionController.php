<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Http\Requests\StorePermissionRequest;
use App\Modules\UserManagement\Http\Resources\PermissionResource;
use App\Modules\UserManagement\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PermissionController extends Controller
{
    public function __construct(
        private readonly UserManagementService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PermissionResource::collection($this->service->listPermissions()),
        ]);
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = $this->service->createPermission($request->validated());

        return response()->json([
            'message' => 'Permission created successfully.',
            'data' => new PermissionResource($permission),
        ], Response::HTTP_CREATED);
    }
}
