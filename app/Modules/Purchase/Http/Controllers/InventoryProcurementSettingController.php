<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Purchase\Domain\InventoryProcurementSetting;
use App\Modules\Purchase\Http\Requests\StoreInventoryProcurementSettingRequest;
use App\Modules\Purchase\Http\Requests\UpdateInventoryProcurementSettingRequest;
use App\Modules\Purchase\Http\Resources\InventoryProcurementSettingResource;
use App\Modules\Purchase\Services\ProcurementMasterService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InventoryProcurementSettingController extends Controller
{
    public function __construct(
        private readonly ProcurementMasterService $procurementMasterService,
    ) {}

    public function index(): JsonResponse
    {
        $inventoryItemId = request()->query('inventoryItemId');

        $query = InventoryProcurementSetting::query()
            ->with(['inventoryItem', 'preferredSupplier'])
            ->orderBy('id');

        if (is_numeric($inventoryItemId)) {
            $query->where('inventory_item_id', (int) $inventoryItemId);
        }

        return response()->json([
            'data' => InventoryProcurementSettingResource::collection($query->get()),
        ]);
    }

    public function store(StoreInventoryProcurementSettingRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['preferredSupplierId'])) {
            $this->procurementMasterService->validateSupplier((int) $data['preferredSupplierId']);
        }

        $row = InventoryProcurementSetting::query()->create([
            'inventory_item_id' => (int) $data['inventoryItemId'],
            'preferred_supplier_id' => $data['preferredSupplierId'] ?? null,
            'minimum_order_qty' => $data['minimumOrderQty'] ?? null,
            'reorder_qty' => $data['reorderQty'] ?? null,
            'lead_time_days' => $data['leadTimeDays'] ?? null,
            'last_purchase_price' => $data['lastPurchasePrice'] ?? null,
            'is_active' => $data['isActive'] ?? true,
        ]);

        return response()->json([
            'message' => 'Procurement setting created successfully.',
            'data' => new InventoryProcurementSettingResource($row->fresh()->load(['inventoryItem', 'preferredSupplier'])),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateInventoryProcurementSettingRequest $request, InventoryProcurementSetting $inventoryProcurementSetting): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('preferredSupplierId', $data) && $data['preferredSupplierId'] !== null) {
            $this->procurementMasterService->validateSupplier((int) $data['preferredSupplierId']);
        }

        $inventoryProcurementSetting->fill([
            'preferred_supplier_id' => array_key_exists('preferredSupplierId', $data)
                ? $data['preferredSupplierId']
                : $inventoryProcurementSetting->preferred_supplier_id,
            'minimum_order_qty' => array_key_exists('minimumOrderQty', $data)
                ? $data['minimumOrderQty']
                : $inventoryProcurementSetting->minimum_order_qty,
            'reorder_qty' => array_key_exists('reorderQty', $data)
                ? $data['reorderQty']
                : $inventoryProcurementSetting->reorder_qty,
            'lead_time_days' => array_key_exists('leadTimeDays', $data)
                ? $data['leadTimeDays']
                : $inventoryProcurementSetting->lead_time_days,
            'last_purchase_price' => array_key_exists('lastPurchasePrice', $data)
                ? $data['lastPurchasePrice']
                : $inventoryProcurementSetting->last_purchase_price,
            'is_active' => array_key_exists('isActive', $data)
                ? (bool) $data['isActive']
                : $inventoryProcurementSetting->is_active,
        ]);
        $inventoryProcurementSetting->save();

        return response()->json([
            'message' => 'Procurement setting updated successfully.',
            'data' => new InventoryProcurementSettingResource($inventoryProcurementSetting->fresh()->load(['inventoryItem', 'preferredSupplier'])),
        ]);
    }

    public function destroy(InventoryProcurementSetting $inventoryProcurementSetting): JsonResponse
    {
        $inventoryProcurementSetting->delete();

        return response()->json([
            'message' => 'Procurement setting deleted successfully.',
        ]);
    }
}
