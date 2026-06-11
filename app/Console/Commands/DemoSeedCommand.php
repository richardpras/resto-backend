<?php

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoDataSeeder;
use Database\Seeders\Demo\DemoSeederContext;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed {--fresh : Run migrate:fresh before seeding} {--outlet= : Limit seeding to a single outlet id}';

    protected $description = 'Seed a full production-like demo environment (DEMO-DATA-SEEDER-01)';

    public function handle(): int
    {
        $outlet = $this->option('outlet');
        DemoSeederContext::$outletIdFilter = $outlet !== null && $outlet !== '' ? (int) $outlet : null;

        if ($this->option('fresh')) {
            $this->components->info('Running migrate:fresh...');
            $this->call('migrate:fresh', ['--force' => true]);
            $this->call(UserManagementPermissionsSeeder::class);
        }

        $started = microtime(true);
        $this->components->info('Seeding demo environment...');

        $this->call(DemoDataSeeder::class);

        $elapsed = round(microtime(true) - $started, 1);
        $this->components->info("Demo seed completed in {$elapsed}s");

        DemoSeederContext::reset();

        return self::SUCCESS;
    }
}
