<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;

trait PassportAuthTestSetup
{
    protected function setUpPassportAuth(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Tests Personal Access Client',
            '--provider' => 'users',
            '--no-interaction' => true,
        ]);
    }
}
