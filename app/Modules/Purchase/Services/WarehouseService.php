<?php

namespace App\Modules\Purchase\Services;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class WarehouseService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): Warehouse
    {
        $outletId = $this->purchaseScopeService->resolveOutletId($actor, $data['outletId']);
        $code = strtoupper(trim((string) $data['code']));

        abort_if(
            Warehouse::query()->where('code', $code)->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Warehouse code already exists.'
        );

        return Warehouse::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => trim((string) $data['name']),
            'type' => $data['type'] ?? 'outlet',
            'is_active' => array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(Warehouse $warehouse, User $actor, array $data): Warehouse
    {
        if ($warehouse->outlet_id !== null) {
            $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $warehouse->outlet_id);
        }

        if (array_key_exists('code', $data)) {
            $code = strtoupper(trim((string) $data['code']));
            abort_if(
                Warehouse::query()->where('code', $code)->where('id', '!=', $warehouse->id)->exists(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Warehouse code already exists.'
            );
            $warehouse->code = $code;
        }

        if (array_key_exists('name', $data)) {
            $warehouse->name = trim((string) $data['name']);
        }

        if (array_key_exists('type', $data)) {
            $warehouse->type = (string) $data['type'];
        }

        if (array_key_exists('isActive', $data)) {
            $warehouse->is_active = (bool) $data['isActive'];
        }

        $warehouse->save();

        return $warehouse->fresh();
    }

    public function deactivate(Warehouse $warehouse, User $actor): Warehouse
    {
        if ($warehouse->outlet_id !== null) {
            $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $warehouse->outlet_id);
        }

        $inUse = DB::table('purchase_orders')->where('destination_warehouse_id', $warehouse->id)->exists()
            || DB::table('goods_receiving_notes')->where('destination_warehouse_id', $warehouse->id)->exists();

        abort_if($inUse, Response::HTTP_UNPROCESSABLE_ENTITY, 'Warehouse is referenced by purchase documents and cannot be deactivated.');

        $warehouse->update(['is_active' => false]);

        return $warehouse->fresh();
    }
}
