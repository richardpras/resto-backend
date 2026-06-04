<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Modules\UserManagement\Http\Requests\StoreDepartmentRequest;
use App\Modules\UserManagement\Http\Requests\UpdateDepartmentRequest;
use App\Modules\UserManagement\Http\Resources\DepartmentResource;
use App\Modules\UserManagement\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId');
        $parsed = $outletId !== null && $outletId !== '' ? (int) $outletId : null;

        return response()->json([
            'data' => DepartmentResource::collection(
                $this->departmentService->list($this->resolveUser($request), $parsed),
            ),
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => new DepartmentResource($department),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $scoped = $this->departmentService->findScoped($this->resolveUser($request), (int) $department->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Department not found.');

        $updated = $this->departmentService->update(
            $this->resolveUser($request),
            $scoped,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => new DepartmentResource($updated),
        ]);
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $scoped = $this->departmentService->findScoped($this->resolveUser($request), (int) $department->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Department not found.');

        $this->departmentService->delete($this->resolveUser($request), $scoped);

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
