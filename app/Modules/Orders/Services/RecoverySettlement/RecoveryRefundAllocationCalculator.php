<?php

namespace App\Modules\Orders\Services\RecoverySettlement;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;

/**
 * Read-only refund allocation suggestions. Does not create payments or journals.
 */
final class RecoveryRefundAllocationCalculator
{
    /**
     * @return array{requested: float, capped: float, allocatedPaidOnLine: float, proportionalPaidShare: float, splitAllocations: list<array{splitId: int|null, label: string|null, amount: float}>, warnings: list<string>}
     */
    public function suggest(Order $order, OrderItem $line, float $requestedRefund): array
    {
        $requestedRefund = max(0, round($requestedRefund, 2));
        $lineGross = $this->lineGross($line);
        $subtotal = max(0.00001, round((float) $order->subtotal, 2));
        $paidTotal = round((float) $order->paid_total, 2);

        $allocatedPaidOnLine = $this->sumAllocationsForLine($order, $line);
        $proportionalPaidShare = round($lineGross / $subtotal * $paidTotal, 2);

        $capBasis = $allocatedPaidOnLine > 0.00001 ? $allocatedPaidOnLine : $proportionalPaidShare;
        $capped = min($requestedRefund, $capBasis, max(0, $paidTotal));

        $warnings = [];
        if ($allocatedPaidOnLine <= 0.00001 && $paidTotal > 0.00001) {
            $warnings[] = 'No explicit payment allocations for this line; cap uses proportional paid share of order subtotal.';
        }
        if ($requestedRefund > $capped + 0.00001) {
            $warnings[] = 'Requested refund exceeds paid share cap; capped for financial safety.';
        }

        $splitAllocations = $this->splitProportionalAllocations($order, $line, $capped);

        return [
            'requested' => $requestedRefund,
            'capped' => round($capped, 2),
            'allocatedPaidOnLine' => round($allocatedPaidOnLine, 2),
            'proportionalPaidShare' => round($proportionalPaidShare, 2),
            'splitAllocations' => $splitAllocations,
            'warnings' => $warnings,
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

    private function sumAllocationsForLine(Order $order, OrderItem $line): float
    {
        $sum = 0.0;
        foreach ($order->payments as $payment) {
            if (strtolower((string) $payment->status) === 'void') {
                continue;
            }
            foreach ($payment->allocations as $alloc) {
                if ((int) $alloc->order_item_id === (int) $line->id) {
                    $sum += (float) $alloc->amount;
                }
            }
        }

        return round($sum, 2);
    }

    /**
     * @return list<array{splitId: int|null, label: string|null, amount: float}>
     */
    private function splitProportionalAllocations(Order $order, OrderItem $line, float $cappedRefund): array
    {
        $splits = $order->splits;
        if ($splits === null || $splits->count() === 0) {
            return [[
                'splitId' => null,
                'label' => null,
                'amount' => round($cappedRefund, 2),
            ]];
        }

        $lineGross = $this->lineGross($line);
        $weights = [];
        foreach ($splits as $split) {
            $w = 0.0;
            foreach ($split->items ?? [] as $si) {
                if ((int) $si->order_item_id === (int) $line->id) {
                    $w += max(0.0, (float) $si->qty);
                }
            }
            $weights[(int) $split->id] = $w;
        }
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.00001) {
            return [[
                'splitId' => null,
                'label' => null,
                'amount' => round($cappedRefund, 2),
            ]];
        }

        $out = [];
        $running = 0.0;
        $nonEmpty = [];
        foreach ($splits as $split) {
            $sid = (int) $split->id;
            $w = $weights[$sid] ?? 0.0;
            if ($w > 0.00001) {
                $nonEmpty[] = $split;
            }
        }
        $n = count($nonEmpty);
        foreach ($nonEmpty as $i => $split) {
            $sid = (int) $split->id;
            $w = $weights[$sid] ?? 0.0;
            $share = ($i === $n - 1)
                ? round($cappedRefund - $running, 2)
                : round($cappedRefund * ($w / $totalWeight), 2);
            $running += $share;
            $out[] = [
                'splitId' => $sid,
                'label' => $split->label ?? null,
                'amount' => max(0, $share),
            ];
        }

        return $out === [] ? [[
            'splitId' => null,
            'label' => null,
            'amount' => round($cappedRefund, 2),
        ]] : $out;
    }
}
