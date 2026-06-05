<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\InventoryProcurementSetting;
use App\Models\Supplier;
use App\Models\Warehouse;
use Symfony\Component\HttpFoundation\Response;

final class ProcurementMasterService
{
    public function validateSupplier(int $supplierId): Supplier
    {
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->find($supplierId);
        abort_if($supplier === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Supplier not found.');
        abort_if(! $supplier->is_active, Response::HTTP_UNPROCESSABLE_ENTITY, 'Supplier is inactive.');

        return $supplier;
    }

    public function findPreferredSupplier(int $inventoryItemId): ?Supplier
    {
        $setting = $this->findProcurementSettings($inventoryItemId);
        if ($setting === null || $setting->preferred_supplier_id === null || ! $setting->is_active) {
            return null;
        }

        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->find((int) $setting->preferred_supplier_id);
        if ($supplier === null || ! $supplier->is_active) {
            return null;
        }

        return $supplier;
    }

    public function findProcurementSettings(int $inventoryItemId): ?InventoryProcurementSetting
    {
        return InventoryProcurementSetting::query()
            ->with('preferredSupplier')
            ->where('inventory_item_id', $inventoryItemId)
            ->first();
    }

    public function validateWarehouse(?int $warehouseId, ?int $outletId = null): ?Warehouse
    {
        if ($warehouseId === null) {
            return null;
        }

        /** @var Warehouse|null $warehouse */
        $warehouse = Warehouse::query()->find($warehouseId);
        abort_if($warehouse === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Warehouse not found.');
        abort_if(! $warehouse->is_active, Response::HTTP_UNPROCESSABLE_ENTITY, 'Warehouse is inactive.');

        if ($outletId !== null && $warehouse->outlet_id !== null && (int) $warehouse->outlet_id !== $outletId) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Warehouse does not belong to outlet.');
        }

        return $warehouse;
    }

    public function resolveGrnDestinationWarehouse(?int $overrideWarehouseId, ?int $poWarehouseId, ?int $outletId): ?int
    {
        $warehouseId = $overrideWarehouseId ?? $poWarehouseId;
        $this->validateWarehouse($warehouseId, $outletId);

        return $warehouseId;
    }
}
