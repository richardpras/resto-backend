<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class TableQrManagementService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function generate(User $user, int $tableId): RestaurantTable
    {
        $table = $this->findScopedTableOrFail($user, $tableId);
        if (! $table->qr_public_id) {
            $table->qr_public_id = $this->newPublicId((int) $table->outlet_id);
        }
        $table->qr_enabled = true;
        $table->qr_version = max(1, (int) $table->qr_version);
        $table->qr_last_rotated_at = now();
        $table->save();

        return $table->fresh();
    }

    public function rotate(User $user, int $tableId): RestaurantTable
    {
        $table = $this->findScopedTableOrFail($user, $tableId);
        $table->qr_public_id = $this->newPublicId((int) $table->outlet_id);
        $table->qr_enabled = true;
        $table->qr_version = max(1, (int) $table->qr_version) + 1;
        $table->qr_last_rotated_at = now();
        $table->save();

        return $table->fresh();
    }

    public function enable(User $user, int $tableId): RestaurantTable
    {
        $table = $this->findScopedTableOrFail($user, $tableId);
        if (! $table->qr_public_id) {
            $table->qr_public_id = $this->newPublicId((int) $table->outlet_id);
            $table->qr_last_rotated_at = now();
        }
        $table->qr_enabled = true;
        $table->save();

        return $table->fresh();
    }

    public function disable(User $user, int $tableId): RestaurantTable
    {
        $table = $this->findScopedTableOrFail($user, $tableId);
        $table->qr_enabled = false;
        $table->save();

        return $table->fresh();
    }

    public function resolveByPublicId(string $qrPublicId): ?RestaurantTable
    {
        return RestaurantTable::query()
            ->where('qr_public_id', $qrPublicId)
            ->where('qr_enabled', true)
            ->where('status', 'active')
            ->where('active', true)
            ->first();
    }

    public function resolveLegacy(int $outletId, int $tableId): ?RestaurantTable
    {
        return RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->whereKey($tableId)
            ->where('status', 'active')
            ->where('active', true)
            ->first();
    }

    public function canonicalUrl(RestaurantTable $table): string
    {
        return app(TableQrService::class)->canonicalUrl($table);
    }

    private function newPublicId(int $outletId): string
    {
        do {
            $candidate = 'TBL_'.strtoupper(Str::random(6));
            $exists = RestaurantTable::query()
                ->where('outlet_id', $outletId)
                ->where('qr_public_id', $candidate)
                ->exists();
        } while ($exists);

        return $candidate;
    }

    private function findScopedTableOrFail(User $user, int $tableId): RestaurantTable
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $table = RestaurantTable::query()
            ->whereIn('outlet_id', $allowed)
            ->whereKey($tableId)
            ->first();
        if ($table === null) {
            throw (new ModelNotFoundException())->setModel(RestaurantTable::class, [$tableId]);
        }

        return $table;
    }
}
