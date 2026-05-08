<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckStalePosSessionsCommand extends Command
{
    protected $signature = 'pos:check-stale-sessions';

    protected $description = 'Run no-op safe stale POS session checks for operational recovery';

    public function handle(): int
    {
        Log::info('Stale POS session check executed (no-op guard).', [
            'scope' => 'pos_session',
            'result' => 'no_modelled_stale_check',
        ]);
        $this->info('Stale POS session check completed (no-op safe guard).');

        return self::SUCCESS;
    }
}
