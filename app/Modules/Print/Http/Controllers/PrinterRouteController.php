<?php

namespace App\Modules\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Print\Http\Requests\AssignPrinterRouteRequest;
use App\Modules\Print\Http\Resources\PrinterRouteResource;
use App\Modules\Print\Services\PrinterManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrinterRouteController extends Controller
{
    public function __construct(
        private readonly PrinterManagementService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = (int) $request->query('outletId', 0);
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return response()->json([
            'data' => PrinterRouteResource::collection($this->service->listRoutes($outletId)),
        ]);
    }

    public function store(AssignPrinterRouteRequest $request): JsonResponse
    {
        $route = $this->service->assignRoute($request->validated());

        return response()->json([
            'message' => 'Printer route assigned successfully.',
            'data' => new PrinterRouteResource($route),
        ], Response::HTTP_CREATED);
    }

    public function destroy(int $route): JsonResponse
    {
        $this->service->deleteRoute($route);

        return response()->json(['message' => 'Printer route deleted successfully.']);
    }
}
