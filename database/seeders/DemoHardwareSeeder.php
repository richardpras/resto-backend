<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoHardwareSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatasetSeederService::seedHardware();
    }
}

