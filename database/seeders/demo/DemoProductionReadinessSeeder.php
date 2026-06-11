<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Support\DemoProductionReadinessPatch;
use Illuminate\Database\Seeder;

/**
 * DEMO-DATA-SEEDER-02 — production readiness patches (stations, routing, QR, payments).
 */
class DemoProductionReadinessSeeder extends Seeder
{
    public function run(): void
    {
        DemoProductionReadinessPatch::apply();
    }
}
