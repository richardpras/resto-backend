<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\Pph21Config;
use App\Modules\HR\Http\Resources\EmployeeTaxProfileResource;
use App\Modules\HR\Services\EmployeeTaxProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class EmployeeTaxProfileController extends Controller
{
    public function __construct(
        private readonly EmployeeTaxProfileService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
        ]);

        return response()->json([
            'data' => EmployeeTaxProfileResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'employeeId' => ['required', 'integer', 'exists:employees,id'],
            'npwpNumber' => ['nullable', 'string', 'max:50'],
            'ptkpStatus' => ['nullable', 'string', Rule::in(Pph21Config::PTKP_STATUSES)],
            'pph21Enabled' => ['nullable', 'boolean'],
        ]);

        $row = $this->service->upsertForEmployee(
            $this->resolveUser(),
            (int) $validated['employeeId'],
            $validated,
        );

        return response()->json([
            'message' => 'Employee tax profile saved.',
            'data' => new EmployeeTaxProfileResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $employeeTaxProfile): JsonResponse
    {
        $validated = request()->validate([
            'npwpNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ptkpStatus' => ['sometimes', 'string', Rule::in(Pph21Config::PTKP_STATUSES)],
            'pph21Enabled' => ['sometimes', 'boolean'],
        ]);

        $row = $this->service->update($this->resolveUser(), $employeeTaxProfile, $validated);

        return response()->json([
            'message' => 'Employee tax profile updated.',
            'data' => new EmployeeTaxProfileResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
