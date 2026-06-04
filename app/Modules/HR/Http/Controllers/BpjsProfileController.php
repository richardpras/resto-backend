<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\BpjsProfileResource;
use App\Modules\HR\Services\BpjsProfileService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BpjsProfileController extends Controller
{
    public function __construct(
        private readonly BpjsProfileService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
        ]);

        return response()->json([
            'data' => BpjsProfileResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'bpjsKesehatanNo' => ['nullable', 'string', 'max:50'],
            'bpjsTkNo' => ['nullable', 'string', 'max:50'],
            'bpjsKesehatanEnabled' => ['nullable', 'boolean'],
            'bpjsTkEnabled' => ['nullable', 'boolean'],
            'bpjsSalaryBase' => ['nullable', 'numeric', 'min:0'],
            'kesehatanEmployeeRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kesehatanCompanyRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jhtEmployeeRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jhtCompanyRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jpEmployeeRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jpCompanyRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jkkCompanyRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jkmCompanyRateOverride' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $row = $this->service->upsertForEmployee(
            $this->resolveUser(),
            (int) $validated['employeeId'],
            $validated,
        );

        return response()->json([
            'message' => 'BPJS profile saved.',
            'data' => new BpjsProfileResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $bpjsProfile): JsonResponse
    {
        $validated = request()->validate([
            'bpjsKesehatanNo' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bpjsTkNo' => ['sometimes', 'nullable', 'string', 'max:50'],
            'bpjsKesehatanEnabled' => ['sometimes', 'boolean'],
            'bpjsTkEnabled' => ['sometimes', 'boolean'],
            'bpjsSalaryBase' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kesehatanEmployeeRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'kesehatanCompanyRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jhtEmployeeRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jhtCompanyRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jpEmployeeRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jpCompanyRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jkkCompanyRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'jkmCompanyRateOverride' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $row = $this->service->update($this->resolveUser(), $bpjsProfile, $validated);

        return response()->json([
            'message' => 'BPJS profile updated.',
            'data' => new BpjsProfileResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
