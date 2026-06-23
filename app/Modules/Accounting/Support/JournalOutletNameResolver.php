<?php

namespace App\Modules\Accounting\Support;

use App\Models\Modules\Settings\Domain\Outlet;

class JournalOutletNameResolver
{
    public static function resolve(?int $outletId, ?string $explicitName): string
    {
        if ($explicitName !== null && trim($explicitName) !== '') {
            return trim($explicitName);
        }

        if ($outletId !== null && $outletId > 0) {
            $name = Outlet::query()->whereKey($outletId)->value('name');
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return 'Main Outlet';
    }
}
