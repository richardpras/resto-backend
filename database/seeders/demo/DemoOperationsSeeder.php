<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoOperationsSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatasetSeederService::seedOutletOps();
        DemoDatasetSeederService::seedTransactions();
        DemoDatasetSeederService::seedHardware();
        DemoDatasetSeederService::seedGiftCardsAndFiscal();
        DemoDatasetSeederService::seedCrmAndLoyalty();
        DemoDatasetSeederService::seedReplayAndMonitoring();
    }
}
