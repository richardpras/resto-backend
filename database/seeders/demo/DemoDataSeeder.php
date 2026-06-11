<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * DEMO-DATA-SEEDER-01 + DEMO-DATA-SEEDER-02 — full business lifecycle demo environment.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoFoundationSeeder::class,
            DemoCatalogSeeder::class,
            DemoHrSeeder::class,
            DemoProcurementSeeder::class,
            DemoOperationsSeeder::class,
            DemoReservationsSeeder::class,
            DemoMonitoringSeeder::class,
            DemoProductionReadinessSeeder::class,
        ]);
    }
}
