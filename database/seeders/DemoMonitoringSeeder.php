<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoMonitoringSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatasetSeederService::seedReplayAndMonitoring();
    }
}

