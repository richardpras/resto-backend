<?php

namespace App\Modules\Orders\Services\RecoverySettlement;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;

/**
 * Suggested loyalty adjustment (informational). Does not mutate loyalty ledgers.
 */
final class RecoveryLoyaltyAdjustmentCalculator
{
    /**
     * @return array{rollbackPointsSuggested: int, regrantPointsSuggested: int, basis: string, warnings: list<string>}
     */
    public function suggest(Order $order, OrderItem $line, ?int $manualPointsDelta): array
    {
        if ($manualPointsDelta !== null) {
            $rollback = $manualPointsDelta < 0 ? abs($manualPointsDelta) : 0;
            $regrant = $manualPointsDelta > 0 ? $manualPointsDelta : 0;

            return [
                'rollbackPointsSuggested' => $rollback,
                'regrantPointsSuggested' => $regrant,
                'basis' => 'manager_override',
                'warnings' => $manualPointsDelta < 0
                    ? ['Manual rollback points — execute via Loyalty module to avoid double reversal.']
                    : [],
            ];
        }

        $lineGross = $this->lineGross($line);
        $subtotal = max(0.00001, round((float) $order->subtotal, 2));
        $ratio = $lineGross / $subtotal;
        $basisPoints = (int) round(100 * $ratio);

        return [
            'rollbackPointsSuggested' => max(0, $basisPoints),
            'regrantPointsSuggested' => 0,
            'basis' => 'proportional_placeholder',
            'warnings' => [
                'Loyalty points are a proportional placeholder (100 baseline × line share). Replace with ledger-derived accrual before executing reversals.',
            ],
        ];
    }

    private function lineGross(OrderItem $line): float
    {
        $lt = $line->line_total;
        if ($lt !== null && (float) $lt > 0) {
            return round((float) $lt, 2);
        }

        return round((float) $line->price * (float) $line->qty, 2);
    }
}
