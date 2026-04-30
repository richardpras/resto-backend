<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\StorePayrollRequest;
use App\Modules\HR\Http\Resources\PayrollResource;
use App\Modules\HR\Services\PayrollService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $service,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        return response()->json([
            'data' => PayrollResource::collection($this->service->listByTenant($tenantId)),
        ]);
    }

    public function store(StorePayrollRequest $request): JsonResponse
    {
        $payroll = $this->service->create($request->validated(), (int) $request->user()->id);

        return response()->json([
            'message' => 'Payroll posted successfully.',
            'data' => new PayrollResource($payroll),
        ], Response::HTTP_CREATED);
    }
}
