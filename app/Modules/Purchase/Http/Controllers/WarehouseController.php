<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Purchase\Http\Resources\WarehouseResource;
use App\Modules\Purchase\Services\PurchaseScopeService;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    public function index(): JsonResponse
    {
        $outletId = $this->purchaseScopeService->requestedOutletIdFromRequest();
        $query = Warehouse::query()->where('is_active', true)->orderBy('name');

        if (is_numeric($outletId) && (int) $outletId >= 1) {
            $query->where(function ($builder) use ($outletId): void {
                $builder->whereNull('outlet_id')->orWhere('outlet_id', (int) $outletId);
            });
        }

        return response()->json([
            'data' => WarehouseResource::collection($query->get()),
        ]);
    }
}
