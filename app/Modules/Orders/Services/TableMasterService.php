<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class TableMasterService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @return Collection<int, RestaurantTable> */
    public function listForOutlet(User $user, int $outletId): Collection
    {
        $this->assertOutletIsAllowed($user, $outletId);

        return RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): RestaurantTable
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletIsAllowed($user, $outletId);

        return RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'code' => $data['code'] ?? null,
            'name' => (string) $data['name'],
            'capacity' => isset($data['capacity']) ? (int) $data['capacity'] : null,
            'zone' => $data['zone'] ?? null,
            'status' => (string) $data['status'],
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
        ]);
    }

    /** @param array<string, mixed> $validated */
    public function update(User $user, int $tableId, array $validated): RestaurantTable
    {
        $table = $this->findScopedTableOrFail($user, $tableId);

        if (array_key_exists('code', $validated)) {
            $table->code = $validated['code'] !== null ? (string) $validated['code'] : null;
        }
        if (isset($validated['name'])) {
            $table->name = (string) $validated['name'];
        }
        if (array_key_exists('capacity', $validated)) {
            $table->capacity = $validated['capacity'] !== null ? (int) $validated['capacity'] : null;
        }
        if (array_key_exists('zone', $validated)) {
            $table->zone = $validated['zone'] !== null ? (string) $validated['zone'] : null;
        }
        if (isset($validated['status'])) {
            $table->status = (string) $validated['status'];
        }
        if (array_key_exists('active', $validated)) {
            $table->active = (bool) $validated['active'];
        }
        $table->save();

        return $table->fresh();
    }

    public function delete(User $user, int $tableId): void
    {
        $this->findScopedTableOrFail($user, $tableId)->delete();
    }

    private function assertOutletIsAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function findScopedTableOrFail(User $user, int $tableId): RestaurantTable
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $table = RestaurantTable::query()
            ->whereIn('outlet_id', $allowed)
            ->whereKey($tableId)
            ->first();
        if ($table === null) {
            throw (new ModelNotFoundException)->setModel(RestaurantTable::class, [(string) $tableId]);
        }

        return $table;
    }
}
