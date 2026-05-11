<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DemoOperationalSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoFoundationSeeder::class,
            DemoOutletSeeder::class,
            DemoMenuSeeder::class,
            DemoInventorySeeder::class,
            DemoTransactionSeeder::class,
            DemoHardwareSeeder::class,
            DemoCRMSeeder::class,
            DemoMonitoringSeeder::class,
            DemoReplaySeeder::class,
        ]);
    }
}

