<?php

namespace Database\Seeders\CustomerDemo;

use Carbon\CarbonImmutable;
use Database\Seeders\CustomerDemo\Support\CustomerDemoProcurementRunner;
use Illuminate\Database\Seeder;

class WrWbProcurementSeeder extends Seeder
{
    public function run(): void
    {
        $actor = CustomerDemoContext::user('manager');
        $runner = app(CustomerDemoProcurementRunner::class);

        $scenarios = [
            ['index' => 1, 'prNo' => 'WRWB-PR-202605-01', 'date' => CustomerDemoContext::date(5), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 1],
            ['index' => 2, 'prNo' => 'WRWB-PR-202605-02', 'date' => CustomerDemoContext::date(7), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 1],
            ['index' => 3, 'prNo' => 'WRWB-PR-202605-03', 'date' => CustomerDemoContext::date(9), 'grnPercent' => 0.5],
            ['index' => 4, 'prNo' => 'WRWB-PR-202605-04', 'date' => CustomerDemoContext::date(11), 'grnPercent' => 0.5],
            ['index' => 5, 'prNo' => 'WRWB-PR-202605-05', 'date' => CustomerDemoContext::date(13), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 0.6],
            ['index' => 6, 'prNo' => 'WRWB-PR-202605-06', 'date' => CustomerDemoContext::date(15), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 0.6],
            ['index' => 7, 'prNo' => 'WRWB-PR-202605-07', 'date' => CustomerDemoContext::date(17), 'poOnly' => true],
            ['index' => 8, 'prNo' => 'WRWB-PR-202605-08', 'date' => CustomerDemoContext::date(19), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 0],
            ['index' => 9, 'prNo' => 'WRWB-PR-202605-09', 'date' => CustomerDemoContext::date(22), 'grnPercent' => 1, 'invoicePercent' => 1, 'paymentPercent' => 0],
            ['index' => 10, 'prNo' => 'WRWB-PR-202605-10', 'date' => CustomerDemoContext::date(25), 'grnPercent' => 0.5, 'invoicePercent' => 0.5, 'paymentPercent' => 1],
        ];

        foreach ($scenarios as $spec) {
            $runner->runScenario($actor, $spec);
        }
    }
}
