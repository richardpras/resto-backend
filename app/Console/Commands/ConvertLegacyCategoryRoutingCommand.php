<?php

namespace App\Console\Commands;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ConvertLegacyCategoryRoutingCommand extends Command
{
    protected $signature = 'menu:convert-legacy-category-routing
        {--outlet= : Optional outlet id}
        {--dry-run : Report only, do not write mappings}';

    protected $description = 'Convert legacy printer category routes into menu_category_printer_mappings';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $outletFilter = is_numeric($this->option('outlet')) ? (int) $this->option('outlet') : null;

        $converted = 0;
        $skipped = 0;
        $unmapped = 0;

        $outlets = Outlet::query()
            ->when($outletFilter !== null, fn ($query) => $query->whereKey($outletFilter))
            ->orderBy('id')
            ->get();

        foreach ($outlets as $outlet) {
            $routes = PrinterRoute::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('print_type', 'kitchen')
                ->where('is_active', true)
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->get();

            foreach ($routes as $route) {
                $categoryName = trim((string) $route->category);
                if ($categoryName === '') {
                    $skipped++;

                    continue;
                }

                $menuCategory = MenuCategory::query()
                    ->where(function ($query) use ($categoryName): void {
                        $query->whereRaw('LOWER(name) = ?', [strtolower($categoryName)])
                            ->orWhereRaw('LOWER(name_en) = ?', [strtolower($categoryName)])
                            ->orWhereRaw('LOWER(name_id) = ?', [strtolower($categoryName)]);
                    })
                    ->orderBy('id')
                    ->first();

                if ($menuCategory === null) {
                    $unmapped++;
                    $this->warn("Outlet {$outlet->id}: no master category for legacy route category [{$categoryName}]");

                    continue;
                }

                if ($route->printer_profile_id === null) {
                    $skipped++;
                    $this->warn("Outlet {$outlet->id}: route {$route->id} has no printer profile");

                    continue;
                }

                if ($dryRun) {
                    $converted++;
                    $this->line("Would map outlet {$outlet->id} category {$menuCategory->name} -> profile {$route->printer_profile_id}");

                    continue;
                }

                MenuCategoryPrinterMapping::query()->updateOrCreate(
                    [
                        'outlet_id' => (int) $outlet->id,
                        'menu_category_id' => (int) $menuCategory->id,
                    ],
                    [
                        'tenant_id' => $menuCategory->tenant_id,
                        'printer_profile_id' => (int) $route->printer_profile_id,
                        'priority' => (int) $route->priority,
                        'is_active' => true,
                        'meta' => [
                            'convertedFromRouteId' => (int) $route->id,
                            'legacyCategory' => $categoryName,
                        ],
                    ],
                );
                $converted++;
            }

            $profiles = PrinterProfile::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('is_active', true)
                ->get();

            foreach ($profiles as $profile) {
                $meta = is_array($profile->meta) ? $profile->meta : [];
                $assigned = data_get($meta, 'assignedCategories', data_get($meta, 'assigned_categories', []));
                if (! is_array($assigned)) {
                    continue;
                }

                foreach ($assigned as $categoryName) {
                    $normalized = trim((string) $categoryName);
                    if ($normalized === '') {
                        continue;
                    }

                    $menuCategory = MenuCategory::query()
                        ->whereRaw('LOWER(name) = ?', [strtolower($normalized)])
                        ->orderBy('id')
                        ->first();

                    if ($menuCategory === null) {
                        $unmapped++;
                        $this->warn("Outlet {$outlet->id}: no master category for assigned category [{$normalized}] on profile {$profile->code}");

                        continue;
                    }

                    if ($dryRun) {
                        $converted++;
                        $this->line("Would map outlet {$outlet->id} assigned category {$normalized} -> profile {$profile->id}");

                        continue;
                    }

                    MenuCategoryPrinterMapping::query()->updateOrCreate(
                        [
                            'outlet_id' => (int) $outlet->id,
                            'menu_category_id' => (int) $menuCategory->id,
                        ],
                        [
                            'tenant_id' => $menuCategory->tenant_id,
                            'printer_profile_id' => (int) $profile->id,
                            'priority' => 100,
                            'is_active' => true,
                            'meta' => [
                                'convertedFromProfileCode' => (string) $profile->code,
                                'legacyCategory' => $normalized,
                            ],
                        ],
                    );
                    $converted++;
                }
            }
        }

        $this->info(sprintf(
            'Legacy category routing conversion: converted=%d skipped=%d unmapped=%d dry_run=%s',
            $converted,
            $skipped,
            $unmapped,
            $dryRun ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }
}
