<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Http\Requests\AssignUserRolesRequest;
use App\Modules\UserManagement\Http\Requests\StoreUserRequest;
use App\Modules\UserManagement\Http\Resources\UserResource;
use App\Modules\UserManagement\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => UserResource::collection($this->service->listUsers()),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->createUser($request->validated());

        return response()->json([
            'message' => 'User created successfully.',
            'data' => new UserResource($user),
        ], Response::HTTP_CREATED);
    }

    public function assignRoles(AssignUserRolesRequest $request, int $user): JsonResponse
    {
        $updated = $this->service->assignRoles($user, $request->validated('roleIds'));
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'User not found');

        return response()->json([
            'message' => 'User roles updated successfully.',
            'data' => new UserResource($updated),
        ]);
    }
}
