<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MenuCategoryPrinterMappingService
{
    /**
     * @return Collection<int,MenuCategoryPrinterMapping>
     */
    public function listForOutlet(int $outletId, bool $activeOnly = false): Collection
    {
        return MenuCategoryPrinterMapping::query()
            ->with(['category', 'printerProfile'])
            ->where('outlet_id', $outletId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function upsert(array $payload): MenuCategoryPrinterMapping
    {
        $outletId = (int) $payload['outletId'];
        $menuCategoryId = (int) $payload['menuCategoryId'];
        $printerProfileId = (int) $payload['printerProfileId'];

        $outlet = Outlet::query()->find($outletId);
        if (! $outlet instanceof Outlet) {
            throw ValidationException::withMessages(['outletId' => ['Outlet not found.']]);
        }
        $category = MenuCategory::query()->find($menuCategoryId);
        if (! $category instanceof MenuCategory) {
            throw ValidationException::withMessages(['menuCategoryId' => ['Menu category not found.']]);
        }
        $profile = PrinterProfile::query()->find($printerProfileId);
        if (! $profile instanceof PrinterProfile) {
            throw ValidationException::withMessages(['printerProfileId' => ['Printer profile not found.']]);
        }
        if ((int) $profile->outlet_id !== $outletId) {
            throw ValidationException::withMessages(['printerProfileId' => ['Printer profile must belong to selected outlet.']]);
        }

        /** @var MenuCategoryPrinterMapping $mapping */
        $mapping = MenuCategoryPrinterMapping::query()->updateOrCreate(
            [
                'outlet_id' => $outletId,
                'menu_category_id' => $menuCategoryId,
            ],
            [
                'tenant_id' => isset($payload['tenantId']) ? (int) $payload['tenantId'] : ($category->tenant_id !== null ? (int) $category->tenant_id : null),
                'printer_profile_id' => $printerProfileId,
                'priority' => isset($payload['priority']) ? (int) $payload['priority'] : 100,
                'is_active' => isset($payload['isActive']) ? (bool) $payload['isActive'] : true,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : null,
            ]
        );

        return $mapping->load(['category', 'printerProfile']);
    }

    public function delete(int $id): void
    {
        MenuCategoryPrinterMapping::query()->whereKey($id)->delete();
    }
}
