<?php

namespace App\Console\Commands;

use App\Modules\System\Services\ProductionCheckService;
use Illuminate\Console\Command;

final class ProductionCheckCommand extends Command
{
    protected $signature = 'system:production-check
                            {--json : Output JSON only}
                            {--allow-non-production : Do not fail on APP_ENV != production}';

    protected $description = 'Validate production environment readiness (infrastructure checks)';

    public function handle(ProductionCheckService $service): int
    {
        $strictProduction = ! (bool) $this->option('allow-non-production');
        $result = $service->run($strictProduction);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Production readiness check — status: '.$result['status']);
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $icon = match ($check['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };
            $this->line(sprintf(
                ' %s [%s] %s — %s',
                $icon,
                $check['category'],
                $check['id'],
                $check['message'],
            ));
        }

        $summary = $result['summary'];
        $this->newLine();
        $this->line(sprintf(
            'Summary: %d pass, %d warn, %d fail',
            $summary['pass'],
            $summary['warn'],
            $summary['fail'],
        ));

        return $result['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }
}
