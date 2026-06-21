<?php

namespace Database\Seeders\Demo;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Reservations\Domain\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DemoReservationsSeeder extends Seeder
{
    public function run(): void
    {
        $base = DemoSeederContext::baseTime();

        foreach (DemoSeederContext::outlets() as $outlet) {
            $tables = RestaurantTable::query()->where('outlet_id', $outlet->id)->limit(4)->get();
            if ($tables->isEmpty()) {
                continue;
            }

            $statuses = [
                ['status' => 'confirmed', 'future' => true],
                ['status' => 'confirmed', 'future' => true],
                ['status' => 'completed', 'future' => false],
                ['status' => 'completed', 'future' => false],
                ['status' => 'cancelled', 'future' => false],
                ['status' => 'no_show', 'future' => false],
            ];

            foreach ($statuses as $i => $cfg) {
                $at = $cfg['future'] ? $base->addDays(2 + $i) : $base->subDays(5 + $i);
                $table = $tables[$i % $tables->count()];

                Reservation::query()->updateOrCreate(
                    ['outlet_id' => $outlet->id, 'reservation_code' => "DEMO-RES-{$outlet->id}-{$i}"],
                    [
                        'table_id' => $table->id,
                        'customer_name' => "Guest {$i}",
                        'customer_phone' => '0812'.str_pad((string) (200000 + $i), 7, '0', STR_PAD_LEFT),
                        'party_size' => 2 + ($i % 4),
                        'reservation_at' => $at,
                        'confirmed_at' => $cfg['status'] !== 'cancelled' ? $at->subHours(2) : null,
                        'checked_in_at' => $cfg['status'] === 'completed' ? $at : null,
                        'seated_at' => $cfg['status'] === 'completed' ? $at->addMinutes(5) : null,
                        'completed_at' => $cfg['status'] === 'completed' ? $at->addHours(2) : null,
                        'cancelled_at' => $cfg['status'] === 'cancelled' ? $at->subHour() : null,
                        'no_show_at' => $cfg['status'] === 'no_show' ? $at->addMinutes(30) : null,
                        'status' => $cfg['status'],
                    ],
                );
            }
        }
    }
}
