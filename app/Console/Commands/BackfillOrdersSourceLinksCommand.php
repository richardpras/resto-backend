<?php

namespace App\Console\Commands;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOrdersSourceLinksCommand extends Command
{
    protected $signature = 'orders:backfill-source-links {--dry-run : Report counts without writing}';

    protected $description = 'Backfill orders.source_type/source_id/source_code from QR links and direct POS defaults';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $qrLinked = 0;
        $directPos = 0;

        $pairs = QrOrderRequest::query()
            ->whereNotNull('order_id')
            ->get(['id', 'order_id', 'request_code']);

        foreach ($pairs as $request) {
            $order = Order::query()->find((int) $request->order_id);
            if ($order === null) {
                continue;
            }

            if (
                (string) ($order->source_type ?? '') === 'qr_order'
                && (int) ($order->source_id ?? 0) === (int) $request->id
            ) {
                continue;
            }

            $qrLinked++;
            if (! $dryRun) {
                $order->update([
                    'source_type' => 'qr_order',
                    'source_id' => (int) $request->id,
                    'source_code' => (string) $request->request_code,
                ]);
            }
        }

        $remaining = Order::query()->whereNull('source_type')->count();
        if (! $dryRun && $remaining > 0) {
            $directPos = Order::query()->whereNull('source_type')->update([
                'source_type' => 'direct_pos',
                'source_id' => null,
                'source_code' => null,
            ]);
        } elseif ($remaining > 0) {
            $directPos = $remaining;
        }

        $this->info(sprintf(
            'Backfill complete%s: %d QR-linked, %d direct POS default.',
            $dryRun ? ' (dry-run)' : '',
            $qrLinked,
            $directPos
        ));

        return self::SUCCESS;
    }
}
