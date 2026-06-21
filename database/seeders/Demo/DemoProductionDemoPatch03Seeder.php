<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Support\DemoProductionDemoPatch03;
use Illuminate\Database\Seeder;

/**
 * DEMO-DATA-SEEDER-03 — customer lifecycle, shift close, inventory/AP, menu intelligence.
 */
class DemoProductionDemoPatch03Seeder extends Seeder
{
    public function run(): void
    {
        DemoProductionDemoPatch03::apply();
    }
}
