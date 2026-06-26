<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Settings\Domain\Tax;

class OrderTaxResolverService
{
    /**
     * @param  list<array<string, mixed>>|null  $taxRules
     * @return array{
     *     subtotalAfterDiscount: float,
     *     tax: float,
     *     total: float,
     *     taxLines: list<array{taxId: string, name: string, type: string, rate: float, inclusive: bool, amount: float}>
     * }
     */
    public function resolve(
        ?int $outletId,
        ?string $serviceMode,
        ?string $orderType,
        float $subtotal,
        float $discount,
        bool $applyTax,
        ?string $asOfDate = null,
        ?array $taxRules = null,
    ): array {
        $subtotalAfterDiscount = max(0.0, round($subtotal - $discount, 2));

        if (! $applyTax || $outletId === null || $outletId < 1) {
            return $this->emptyResult($subtotalAfterDiscount);
        }

        $rules = $taxRules ?? $this->loadRulesForOutlet($outletId, $asOfDate);
        $matching = $this->filterMatchingRules($rules, $serviceMode, $orderType);

        if ($matching === []) {
            return $this->emptyResult($subtotalAfterDiscount);
        }

        $taxLines = [];
        $taxTotal = 0.0;
        $runningBase = $subtotalAfterDiscount;

        foreach ($matching as $rule) {
            $amount = $this->calculateLineAmount($rule, $runningBase);
            if ($amount <= 0) {
                continue;
            }

            $taxLines[] = [
                'taxId' => (string) $rule['id'],
                'name' => (string) $rule['name'],
                'type' => (string) $rule['type'],
                'rate' => (float) $rule['value'],
                'inclusive' => (bool) ($rule['inclusive'] ?? false),
                'amount' => round($amount, 2),
            ];
            $taxTotal += $amount;

            if (($rule['type'] ?? '') === 'percentage' && ! ($rule['inclusive'] ?? false)) {
                $runningBase += $amount;
            }
        }

        $taxTotal = round($taxTotal, 2);
        $total = round($subtotalAfterDiscount + $taxTotal, 2);

        return [
            'subtotalAfterDiscount' => $subtotalAfterDiscount,
            'tax' => $taxTotal,
            'total' => $total,
            'taxLines' => $taxLines,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadRulesForOutlet(int $outletId, ?string $asOfDate = null): array
    {
        $date = $asOfDate ?? now()->toDateString();

        return Tax::query()
            ->where('status', 'active')
            ->whereHas('outletAssignments', fn ($q) => $q->where('outlet_id', $outletId))
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Tax $tax): array => [
                'id' => $tax->id,
                'name' => $tax->name,
                'type' => $tax->type,
                'value' => (float) $tax->value,
                'applyDineIn' => (bool) $tax->apply_dine_in,
                'applyTakeaway' => (bool) $tax->apply_takeaway,
                'inclusive' => (bool) $tax->inclusive,
                'status' => $tax->status,
                'effectiveFrom' => $tax->effective_from?->toDateString(),
                'effectiveTo' => $tax->effective_to?->toDateString(),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<array<string, mixed>>
     */
    public function filterMatchingRules(array $rules, ?string $serviceMode, ?string $orderType): array
    {
        $isTakeaway = $this->isTakeawayMode($serviceMode, $orderType);

        return array_values(array_filter($rules, function (array $rule) use ($isTakeaway): bool {
            if (($rule['status'] ?? 'inactive') !== 'active') {
                return false;
            }

            return $isTakeaway
                ? (bool) ($rule['applyTakeaway'] ?? false)
                : (bool) ($rule['applyDineIn'] ?? false);
        }));
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function calculateLineAmount(array $rule, float $base): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        $type = (string) ($rule['type'] ?? 'percentage');
        $value = (float) ($rule['value'] ?? 0);

        if ($type === 'fixed') {
            return max(0.0, $value);
        }

        if ($value <= 0) {
            return 0.0;
        }

        if ($rule['inclusive'] ?? false) {
            return $base - ($base / (1 + ($value / 100)));
        }

        return $base * ($value / 100);
    }

    private function isTakeawayMode(?string $serviceMode, ?string $orderType): bool
    {
        $mode = strtolower(trim((string) ($serviceMode ?? '')));
        if (in_array($mode, ['takeaway', 'take_away', 'take-away'], true)) {
            return true;
        }

        $type = strtolower(trim((string) ($orderType ?? '')));

        return in_array($type, ['takeaway', 'take away', 'take-away', 'online'], true);
    }

    /**
     * @return array{
     *     subtotalAfterDiscount: float,
     *     tax: float,
     *     total: float,
     *     taxLines: list<array{taxId: string, name: string, type: string, rate: float, inclusive: bool, amount: float}>
     * }
     */
    private function emptyResult(float $subtotalAfterDiscount): array
    {
        return [
            'subtotalAfterDiscount' => $subtotalAfterDiscount,
            'tax' => 0.0,
            'total' => round($subtotalAfterDiscount, 2),
            'taxLines' => [],
        ];
    }
}
