<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Http\Resources\EmployeeResource;
use App\Modules\HR\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $service,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        return response()->json([
            'data' => EmployeeResource::collection($this->service->listByTenant($tenantId)),
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Employee created successfully.',
            'data' => new EmployeeResource($employee),
        ], Response::HTTP_CREATED);
    }

    public function show(int $employee): JsonResponse
    {
        return response()->json([
            'data' => new EmployeeResource($this->service->find($employee)),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, int $employee): JsonResponse
    {
        $updated = $this->service->update($employee, $request->validated());

        return response()->json([
            'message' => 'Employee updated successfully.',
            'data' => new EmployeeResource($updated),
        ]);
    }

    public function destroy(int $employee): JsonResponse
    {
        $this->service->delete($employee);

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }
}
