<?php

namespace App\Modules\Production\Support;

final class DefaultProductionStationCatalog
{
    /** @return list<array{code: string, name: string, type: string, display_order: int, kds_enabled: bool, print_enabled: bool}> */
    public static function all(): array
    {
        return [
            [
                'code' => 'kitchen',
                'name' => 'Kitchen',
                'type' => 'kitchen',
                'display_order' => 10,
                'kds_enabled' => true,
                'print_enabled' => true,
            ],
            [
                'code' => 'bar',
                'name' => 'Bar',
                'type' => 'bar',
                'display_order' => 20,
                'kds_enabled' => true,
                'print_enabled' => true,
            ],
            [
                'code' => 'cashier',
                'name' => 'Cashier',
                'type' => 'cashier',
                'display_order' => 30,
                'kds_enabled' => false,
                'print_enabled' => false,
            ],
            [
                'code' => 'dessert',
                'name' => 'Dessert',
                'type' => 'dessert',
                'display_order' => 40,
                'kds_enabled' => true,
                'print_enabled' => true,
            ],
            [
                'code' => 'bakery',
                'name' => 'Bakery',
                'type' => 'bakery',
                'display_order' => 50,
                'kds_enabled' => true,
                'print_enabled' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, name: string, type: string, display_order: int, kds_enabled: bool, print_enabled: bool}>
     */
    public static function forCodes(array $codes): array
    {
        $byCode = collect(self::all())->keyBy('code');

        return collect($codes)
            ->map(static fn (string $code): ?array => $byCode->get(strtolower($code)))
            ->filter()
            ->values()
            ->all();
    }
}
