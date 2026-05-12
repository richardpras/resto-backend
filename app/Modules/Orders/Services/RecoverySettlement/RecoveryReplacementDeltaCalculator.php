<?php

namespace App\Modules\Orders\Services\RecoverySettlement;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;

/**
 * Replacement price delta (new line vs original). Read-only.
 */
final class RecoveryReplacementDeltaCalculator
{
    /**
     * @return array{originalLineGross: float, replacementLineGross: float, delta: float, replacedByOrderItemId: int|null, warnings: list<string>}
     */
    public function delta(Order $order, OrderItem $original, ?int $replacedByOrderItemId): array
    {
        $originalGross = $this->lineGross($original);
        if ($replacedByOrderItemId === null || $replacedByOrderItemId <= 0) {
            return [
                'originalLineGross' => $originalGross,
                'replacementLineGross' => 0.0,
                'delta' => 0.0,
                'replacedByOrderItemId' => null,
                'warnings' => ['No replacement line id; delta is zero until a replacement item is linked.'],
            ];
        }

        $replacement = $order->items->first(static function (OrderItem $it) use ($replacedByOrderItemId): bool {
            return (int) $it->id === (int) $replacedByOrderItemId;
        });
        if (! $replacement instanceof OrderItem) {
            throw new \InvalidArgumentException('Replacement order item not found on this order.');
        }

        $replacementGross = $this->lineGross($replacement);
        $delta = round($replacementGross - $originalGross, 2);

        return [
            'originalLineGross' => $originalGross,
            'replacementLineGross' => $replacementGross,
            'delta' => $delta,
            'replacedByOrderItemId' => $replacedByOrderItemId,
            'warnings' => $delta > 0
                ? ['Replacement is more expensive than original; capture extra settlement in POS if needed.']
                : [],
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
