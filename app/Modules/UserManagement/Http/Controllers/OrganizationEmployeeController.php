<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Modules\UserManagement\Http\Requests\AssignEmployeeUserRequest;
use App\Modules\UserManagement\Http\Requests\StoreOrganizationEmployeeRequest;
use App\Modules\UserManagement\Http\Requests\UpdateOrganizationEmployeeRequest;
use App\Modules\UserManagement\Http\Resources\OrganizationEmployeeResource;
use App\Modules\UserManagement\Services\OrganizationEmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationEmployeeController extends Controller
{
    public function __construct(
        private readonly OrganizationEmployeeService $employeeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $outletId = (int) $request->query('outletId');
        $search = $request->query('search');

        $employees = $search !== null && trim((string) $search) !== ''
            ? $this->employeeService->search($this->resolveUser($request), $outletId, (string) $search)
            : $this->employeeService->list($this->resolveUser($request), $outletId);

        return response()->json([
            'data' => OrganizationEmployeeResource::collection($employees),
        ]);
    }

    public function store(StoreOrganizationEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Employee created successfully.',
            'data' => new OrganizationEmployeeResource($employee),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $scoped = $this->employeeService->findScoped($this->resolveUser($request), (int) $employee->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        return response()->json([
            'data' => new OrganizationEmployeeResource($scoped),
        ]);
    }

    public function update(UpdateOrganizationEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $scoped = $this->employeeService->findScoped($this->resolveUser($request), (int) $employee->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        $updated = $this->employeeService->update(
            $this->resolveUser($request),
            $scoped,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Employee updated successfully.',
            'data' => new OrganizationEmployeeResource($updated),
        ]);
    }

    public function assignUser(AssignEmployeeUserRequest $request, Employee $employee): JsonResponse
    {
        $scoped = $this->employeeService->findScoped($this->resolveUser($request), (int) $employee->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        $updated = $this->employeeService->assignUser(
            $this->resolveUser($request),
            $scoped,
            (int) $request->validated('userId'),
        );

        return response()->json([
            'message' => 'User linked to employee successfully.',
            'data' => new OrganizationEmployeeResource($updated),
        ]);
    }

    public function removeUser(Request $request, Employee $employee): JsonResponse
    {
        $scoped = $this->employeeService->findScoped($this->resolveUser($request), (int) $employee->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Employee not found.');

        $updated = $this->employeeService->removeUser($this->resolveUser($request), $scoped);

        return response()->json([
            'message' => 'User link removed from employee.',
            'data' => new OrganizationEmployeeResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
