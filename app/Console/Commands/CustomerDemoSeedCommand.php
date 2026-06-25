<?php

namespace App\Console\Commands;

use Database\Seeders\AccountingPostingMappingsSeeder;
use Database\Seeders\CustomerDemo\CustomerDemoContext;
use Database\Seeders\CustomerDemo\WrWbMay2026Seeder;
use Database\Seeders\EssentialCoaAccountsSeeder;
use Database\Seeders\PaymentBankCoaLinkSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Console\Command;

class CustomerDemoSeedCommand extends Command
{
    protected $signature = 'demo:seed-wrwb {--fresh : Run migrate:fresh before seeding}';

    protected $description = 'Seed WR WB customer demo environment (May 2026, single outlet)';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->components->info('Running migrate:fresh...');
            $this->call('migrate:fresh', ['--force' => true]);
            $this->call(UserManagementPermissionsSeeder::class);
            $this->call(EssentialCoaAccountsSeeder::class);
            $this->call(AccountingPostingMappingsSeeder::class);
            $this->call(PaymentBankCoaLinkSeeder::class);
        }

        $started = microtime(true);
        $this->components->info('Seeding WR WB customer demo (May 2026)...');

        $this->call(WrWbMay2026Seeder::class);

        $elapsed = round(microtime(true) - $started, 1);
        $this->components->info("WR WB demo seed completed in {$elapsed}s");
        $this->components->info('Login admin: admin@wrwb.demo / demo123 (PIN 0000)');
        $this->components->info('Login owner: owner@wrwb.demo / demo123 (PIN 1234)');

        CustomerDemoContext::reset();

        return self::SUCCESS;
    }
}
