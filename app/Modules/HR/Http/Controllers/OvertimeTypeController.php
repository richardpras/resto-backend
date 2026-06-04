<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\OvertimeTypeResource;
use App\Modules\HR\Services\OvertimeTypeService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OvertimeTypeController extends Controller
{
    public function __construct(
        private readonly OvertimeTypeService $service,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->service->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'isActive' => request()->query('isActive'),
        ]);

        return response()->json([
            'data' => OvertimeTypeResource::collection($rows),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $row = $this->service->create($this->resolveUser(), $validated);

        return response()->json([
            'message' => 'Overtime type created.',
            'data' => new OvertimeTypeResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $overtimeType): JsonResponse
    {
        $validated = request()->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
        ]);

        $row = $this->service->update($this->resolveUser(), $overtimeType, $validated);

        return response()->json([
            'message' => 'Overtime type updated.',
            'data' => new OvertimeTypeResource($row),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
