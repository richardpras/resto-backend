<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\StoreOutletRequest;
use App\Modules\Settings\Http\Requests\UpdateOutletRequest;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OutletSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function index(): JsonResponse
    {
        $perPage = max(1, (int) request()->integer('per_page', 10));
        $page = max(1, (int) request()->integer('page', 1));

        $result = $this->domain->listOutletsForUserPaginated(request()->user(), $perPage, $page);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => $result->items(),
            'meta' => [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
            ],
        ]);
    }

    public function store(StoreOutletRequest $request): JsonResponse
    {
        $v = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Outlet created successfully.',
            'data' => $this->domain->createOutlet($v),
            'meta' => new \stdClass(),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateOutletRequest $request, int $outletId): JsonResponse
    {
        $v = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Outlet updated successfully.',
            'data' => $this->domain->updateOutletForUser($request->user(), $outletId, $v),
            'meta' => new \stdClass(),
        ]);
    }

    public function destroy(int $outletId): JsonResponse
    {
        $this->domain->deleteOutletForUser(request()->user(), $outletId);

        return response()->json([
            'success' => true,
            'message' => 'Outlet deleted successfully.',
            'data' => new \stdClass(),
            'meta' => new \stdClass(),
        ]);
    }
}
