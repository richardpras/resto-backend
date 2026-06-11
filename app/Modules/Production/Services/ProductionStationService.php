<?php

namespace App\Modules\Production\Services;

use App\Models\Modules\Production\Domain\ProductionStation;
use App\Modules\Production\Repositories\ProductionStationRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionStationService
{
    public function __construct(
        private readonly ProductionStationRepositoryInterface $repository,
    ) {}

    /**
     * @return Collection<int, ProductionStation>
     */
    public function listForOutlet(int $outletId, bool $activeOnly = false): Collection
    {
        return $this->repository->listForOutlet($outletId, $activeOnly);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function create(array $payload): ProductionStation
    {
        $outletId = (int) $payload['outletId'];
        $code = $this->normalizeCode((string) ($payload['code'] ?? $payload['name'] ?? ''));

        if ($this->repository->findByOutletAndCode($outletId, $code) !== null) {
            throw ValidationException::withMessages([
                'code' => ['A production station with this code already exists for the outlet.'],
            ]);
        }

        return $this->repository->create([
            'tenant_id' => isset($payload['tenantId']) ? (int) $payload['tenantId'] : null,
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => (string) $payload['name'],
            'type' => (string) ($payload['type'] ?? $code),
            'display_order' => (int) ($payload['displayOrder'] ?? 100),
            'is_active' => (bool) ($payload['isActive'] ?? true),
            'kds_enabled' => (bool) ($payload['kdsEnabled'] ?? true),
            'print_enabled' => (bool) ($payload['printEnabled'] ?? true),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(int $id, array $payload): ProductionStation
    {
        $station = $this->repository->findById($id);
        if ($station === null) {
            throw ValidationException::withMessages([
                'id' => ['Production station not found.'],
            ]);
        }

        $attributes = [];
        if (array_key_exists('name', $payload)) {
            $attributes['name'] = (string) $payload['name'];
        }
        if (array_key_exists('type', $payload)) {
            $attributes['type'] = (string) $payload['type'];
        }
        if (array_key_exists('displayOrder', $payload)) {
            $attributes['display_order'] = (int) $payload['displayOrder'];
        }
        if (array_key_exists('kdsEnabled', $payload)) {
            $attributes['kds_enabled'] = (bool) $payload['kdsEnabled'];
        }
        if (array_key_exists('printEnabled', $payload)) {
            $attributes['print_enabled'] = (bool) $payload['printEnabled'];
        }
        if (array_key_exists('code', $payload)) {
            $code = $this->normalizeCode((string) $payload['code']);
            $existing = $this->repository->findByOutletAndCode((int) $station->outlet_id, $code);
            if ($existing !== null && (int) $existing->id !== (int) $station->id) {
                throw ValidationException::withMessages([
                    'code' => ['A production station with this code already exists for the outlet.'],
                ]);
            }
            $attributes['code'] = $code;
        }

        if ($attributes !== []) {
            $this->repository->update($station, $attributes);
        }

        return $this->repository->findById($id) ?? $station;
    }

    public function updateStatus(int $id, bool $isActive): ProductionStation
    {
        $station = $this->repository->findById($id);
        if ($station === null) {
            throw ValidationException::withMessages([
                'id' => ['Production station not found.'],
            ]);
        }

        $this->repository->update($station, ['is_active' => $isActive]);

        return $this->repository->findById($id) ?? $station;
    }

    private function normalizeCode(string $value): string
    {
        $slug = Str::slug($value, '_');
        if ($slug === '') {
            throw ValidationException::withMessages([
                'code' => ['Station code is required.'],
            ]);
        }

        return strtolower($slug);
    }
}
