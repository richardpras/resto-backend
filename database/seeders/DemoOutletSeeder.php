<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoOutletSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatasetSeederService::seedOutletOps();
    }
}

