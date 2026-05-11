<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoDatasetSeederService;
use Illuminate\Database\Seeder;

class DemoTransactionSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatasetSeederService::seedTransactions();
        DemoDatasetSeederService::seedGiftCardsAndFiscal();
    }
}

