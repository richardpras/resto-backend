<?php

namespace App\Modules\Imports\Support;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Modules\Settings\Support\PaymentMethodCatalog;

final class ImportRelationListProvider
{
    /**
     * @return list<string>
     */
    public function listForRelation(int $outletId, string $relation): array
    {
        return match ($relation) {
            'ingredients' => $this->ingredientCodes($outletId),
            'menu_categories' => $this->menuCategoryCodes($outletId),
            'menu_items' => $this->menuItemCodes($outletId),
            'accounts' => $this->accountCodes(),
            'customers' => $this->customerCodes($outletId),
            'departments' => $this->departmentCodes($outletId),
            'positions' => $this->positionCodes($outletId),
            'employees' => $this->employeeNos($outletId),
            'payment_methods' => $this->paymentMethodCodes(),
            default => [],
        };
    }

    /**
     * @param  list<ImportSheetDefinition>  $sheets
     * @return array<string, list<string>>
     */
    public function collectRelationLists(int $outletId, array $sheets): array
    {
        $relations = [];
        foreach ($sheets as $sheet) {
            foreach ($sheet->columns as $column) {
                if ($column->relation !== null) {
                    $relations[$column->relation] = true;
                }
            }
        }

        $lists = [];
        foreach (array_keys($relations) as $relation) {
            $lists[$relation] = $this->listForRelation($outletId, $relation);
        }

        return $lists;
    }

    /**
     * @return list<string>
     */
    private function ingredientCodes(int $outletId): array
    {
        return Ingredient::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('import_code')
            ->orderBy('import_code')
            ->pluck('import_code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function menuCategoryCodes(int $outletId): array
    {
        return MenuCategory::query()
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function menuItemCodes(int $outletId): array
    {
        return MenuItem::query()
            ->where('outlet_id', $outletId)
            ->whereNotNull('import_code')
            ->orderBy('import_code')
            ->pluck('import_code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function accountCodes(): array
    {
        return Account::query()
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function customerCodes(int $outletId): array
    {
        return LoyaltyAccount::query()
            ->where('outlet_id', $outletId)
            ->whereNull('merged_into_account_id')
            ->whereNotNull('import_code')
            ->orderBy('import_code')
            ->pluck('import_code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function departmentCodes(int $outletId): array
    {
        return Department::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function positionCodes(int $outletId): array
    {
        return Position::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->orderBy('code')
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function employeeNos(int $outletId): array
    {
        return Employee::query()
            ->where('outlet_id', $outletId)
            ->orderBy('employee_no')
            ->pluck('employee_no')
            ->map(static fn ($no): string => (string) $no)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function paymentMethodCodes(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['paymentMethodCode'],
            PaymentMethodCatalog::defaultRows(),
        );
    }
}
