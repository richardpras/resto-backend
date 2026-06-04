<?php

namespace App\Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Modules\UserManagement\Http\Requests\StorePositionRequest;
use App\Modules\UserManagement\Http\Requests\UpdatePositionRequest;
use App\Modules\UserManagement\Http\Resources\PositionResource;
use App\Modules\UserManagement\Services\PositionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PositionController extends Controller
{
    public function __construct(
        private readonly PositionService $positionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId');
        $parsedOutlet = $outletId !== null && $outletId !== '' ? (int) $outletId : null;
        $departmentId = $request->query('departmentId');
        $parsedDept = $departmentId !== null && $departmentId !== '' ? (int) $departmentId : null;

        return response()->json([
            'data' => PositionResource::collection(
                $this->positionService->list($this->resolveUser($request), $parsedOutlet, $parsedDept),
            ),
        ]);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = $this->positionService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Position created successfully.',
            'data' => new PositionResource($position->load('department')),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $scoped = $this->positionService->findScoped($this->resolveUser($request), (int) $position->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Position not found.');

        $updated = $this->positionService->update(
            $this->resolveUser($request),
            $scoped,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Position updated successfully.',
            'data' => new PositionResource($updated),
        ]);
    }

    public function destroy(Request $request, Position $position): JsonResponse
    {
        $scoped = $this->positionService->findScoped($this->resolveUser($request), (int) $position->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Position not found.');

        $this->positionService->delete($this->resolveUser($request), $scoped);

        return response()->json([
            'message' => 'Position deleted successfully.',
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
