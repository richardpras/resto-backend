<?php

namespace App\Modules\PromotionEngine\Services;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PromotionEvaluationService
{
    /** @var list<string> */
    private const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /**
     * @param  array<int, array{id: string, name?: string, price: float, qty: float, category?: string|null}>  $cartLines
     * @return list<array{
     *     promotionId: int,
     *     promotionCode: string,
     *     promotionName: string,
     *     discountType: string,
     *     discountValue: float,
     *     discountAmount: float,
     *     priority: int,
     *     appliedItems: list<array{itemId: string, qty: float, discount: float}>
     * }>
     */
    public function evaluateCandidates(Collection $promotions, array $cartLines, float $subtotal, ?Carbon $at = null): array
    {
        $at ??= now();
        $results = [];

        foreach ($promotions as $promotion) {
            if (! $this->isEligible($promotion, $cartLines, $subtotal, $at)) {
                continue;
            }

            $evaluation = $this->calculateDiscount($promotion, $cartLines, $subtotal);
            if ($evaluation['discountAmount'] <= 0) {
                continue;
            }

            $results[] = [
                'promotionId' => (int) $promotion->id,
                'promotionCode' => (string) $promotion->code,
                'promotionName' => (string) $promotion->name,
                'discountType' => (string) $promotion->type,
                'discountValue' => $evaluation['discountValue'],
                'discountAmount' => $evaluation['discountAmount'],
                'priority' => (int) $promotion->priority,
                'appliedItems' => $evaluation['appliedItems'],
            ];
        }

        usort($results, function (array $a, array $b): int {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            if ($a['discountAmount'] !== $b['discountAmount']) {
                return $b['discountAmount'] <=> $a['discountAmount'];
            }

            return $a['promotionId'] <=> $b['promotionId'];
        });

        return $results;
    }

    /**
     * @param  array<int, array{id: string, name?: string, price: float, qty: float, category?: string|null}>  $cartLines
     * @return array{
     *     promotionId: int,
     *     promotionCode: string,
     *     promotionName: string,
     *     discountType: string,
     *     discountValue: float,
     *     discountAmount: float,
     *     priority: int,
     *     appliedItems: list<array{itemId: string, qty: float, discount: float}>
     * }|null
     */
    public function pickBest(Collection $promotions, array $cartLines, float $subtotal, ?Carbon $at = null): ?array
    {
        $candidates = $this->evaluateCandidates($promotions, $cartLines, $subtotal, $at);

        return $candidates[0] ?? null;
    }

    /**
     * @param  array<int, array{id: string, name?: string, price: float, qty: float, category?: string|null}>  $cartLines
     */
    public function isEligible(Promotion $promotion, array $cartLines, float $subtotal, ?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $promotion->is_active) {
            return false;
        }

        if ($promotion->valid_from !== null && $at->lt($promotion->valid_from)) {
            return false;
        }

        if ($promotion->valid_until !== null && $at->gt($promotion->valid_until)) {
            return false;
        }

        $conditions = is_array($promotion->conditions) ? $promotion->conditions : [];
        $minSpend = (float) ($conditions['minSpend'] ?? 0);
        if ($minSpend > 0 && $subtotal < $minSpend) {
            return false;
        }

        $menuItemIds = $this->normalizeStringList($conditions['menuItemIds'] ?? []);
        if ($menuItemIds !== []) {
            $hasItem = false;
            foreach ($cartLines as $line) {
                if (in_array((string) $line['id'], $menuItemIds, true)) {
                    $hasItem = true;
                    break;
                }
            }
            if (! $hasItem) {
                return false;
            }
        }

        $categories = $this->normalizeStringList($conditions['categories'] ?? []);
        if ($categories !== []) {
            $hasCategory = false;
            foreach ($cartLines as $line) {
                $category = isset($line['category']) ? (string) $line['category'] : '';
                if ($category !== '' && in_array($category, $categories, true)) {
                    $hasCategory = true;
                    break;
                }
            }
            if (! $hasCategory) {
                return false;
            }
        }

        $dayRestriction = $this->normalizeStringList($conditions['dayRestriction'] ?? []);
        if ($dayRestriction !== []) {
            $dayName = self::DAY_NAMES[(int) $at->dayOfWeek];
            if (! in_array($dayName, $dayRestriction, true)) {
                return false;
            }
        }

        $timeStart = isset($conditions['timeStart']) ? (string) $conditions['timeStart'] : '';
        $timeEnd = isset($conditions['timeEnd']) ? (string) $conditions['timeEnd'] : '';
        if ($timeStart !== '' && $timeEnd !== '') {
            $hhmm = $at->format('H:i');
            if ($hhmm < $timeStart || $hhmm > $timeEnd) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{id: string, name?: string, price: float, qty: float, category?: string|null}>  $cartLines
     * @return array{
     *     discountValue: float,
     *     discountAmount: float,
     *     appliedItems: list<array{itemId: string, qty: float, discount: float}>
     * }
     */
    public function calculateDiscount(Promotion $promotion, array $cartLines, float $subtotal): array
    {
        if ($subtotal <= 0) {
            return [
                'discountValue' => 0.0,
                'discountAmount' => 0.0,
                'appliedItems' => [],
            ];
        }

        $config = is_array($promotion->config) ? $promotion->config : [];
        $conditions = is_array($promotion->conditions) ? $promotion->conditions : [];
        $scopedItemIds = $this->resolveScopedMenuItemIds($promotion->type, $config, $conditions);
        $eligibleLines = $this->filterEligibleLines($cartLines, $scopedItemIds);

        return match ($promotion->type) {
            Promotion::TYPE_PERCENTAGE_ORDER => $this->calculatePercentageOrder($config, $subtotal),
            Promotion::TYPE_PERCENTAGE_ITEMS => $this->calculatePercentageItems($config, $eligibleLines, $subtotal),
            Promotion::TYPE_FIXED_AMOUNT => $this->calculateFixedAmount($config, $subtotal),
            Promotion::TYPE_BUY_X_GET_Y => $this->calculateBuyXGetY($config, $eligibleLines, $subtotal),
            default => [
                'discountValue' => 0.0,
                'discountAmount' => 0.0,
                'appliedItems' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{discountValue: float, discountAmount: float, appliedItems: list<array{itemId: string, qty: float, discount: float}>}
     */
    private function calculatePercentageOrder(array $config, float $subtotal): array
    {
        $rate = max(0.0, (float) ($config['rate'] ?? 0));
        $discount = round($subtotal * ($rate / 100), 2);
        $maxDiscount = isset($config['maxDiscount']) ? (float) $config['maxDiscount'] : null;
        if ($maxDiscount !== null && $maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }

        return [
            'discountValue' => $rate,
            'discountAmount' => min($subtotal, max(0.0, $discount)),
            'appliedItems' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array{id: string, price: float, qty: float}>  $eligibleLines
     * @return array{discountValue: float, discountAmount: float, appliedItems: list<array{itemId: string, qty: float, discount: float}>}
     */
    private function calculatePercentageItems(array $config, array $eligibleLines, float $subtotal): array
    {
        $rate = max(0.0, (float) ($config['rate'] ?? 0));
        $eligibleSubtotal = 0.0;
        foreach ($eligibleLines as $line) {
            $eligibleSubtotal += (float) $line['price'] * (float) $line['qty'];
        }

        if ($eligibleSubtotal <= 0) {
            return [
                'discountValue' => $rate,
                'discountAmount' => 0.0,
                'appliedItems' => [],
            ];
        }

        $discount = round($eligibleSubtotal * ($rate / 100), 2);
        $maxDiscount = isset($config['maxDiscount']) ? (float) $config['maxDiscount'] : null;
        if ($maxDiscount !== null && $maxDiscount > 0) {
            $discount = min($discount, $maxDiscount);
        }

        return [
            'discountValue' => $rate,
            'discountAmount' => min($subtotal, max(0.0, $discount)),
            'appliedItems' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{discountValue: float, discountAmount: float, appliedItems: list<array{itemId: string, qty: float, discount: float}>}
     */
    private function calculateFixedAmount(array $config, float $subtotal): array
    {
        $amount = max(0.0, (float) ($config['amount'] ?? 0));

        return [
            'discountValue' => $amount,
            'discountAmount' => min($subtotal, $amount),
            'appliedItems' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, array{id: string, price: float, qty: float}>  $eligibleLines
     * @return array{discountValue: float, discountAmount: float, appliedItems: list<array{itemId: string, qty: float, discount: float}>}
     */
    private function calculateBuyXGetY(array $config, array $eligibleLines, float $subtotal): array
    {
        $buyQty = max(1, (int) ($config['buyQty'] ?? 1));
        $getQty = max(1, (int) ($config['getQty'] ?? 1));
        $bundleSize = $buyQty + $getQty;

        $totalDiscount = 0.0;
        $appliedItems = [];

        foreach ($eligibleLines as $line) {
            $qty = (float) $line['qty'];
            $price = (float) $line['price'];
            $sets = (int) floor($qty / $bundleSize);
            if ($sets < 1) {
                continue;
            }

            $lineDiscount = round($sets * $getQty * $price, 2);
            if ($lineDiscount <= 0) {
                continue;
            }

            $totalDiscount += $lineDiscount;
            $appliedItems[] = [
                'itemId' => (string) $line['id'],
                'qty' => (float) ($sets * $getQty),
                'discount' => $lineDiscount,
            ];
        }

        return [
            'discountValue' => (float) $getQty,
            'discountAmount' => min($subtotal, max(0.0, round($totalDiscount, 2))),
            'appliedItems' => $appliedItems,
        ];
    }

    /**
     * @param  array<int, array{id: string, price: float, qty: float}>  $cartLines
     * @param  list<string>  $scopedItemIds
     * @return array<int, array{id: string, price: float, qty: float}>
     */
    private function filterEligibleLines(array $cartLines, array $scopedItemIds): array
    {
        if ($scopedItemIds === []) {
            return $cartLines;
        }

        return array_values(array_filter(
            $cartLines,
            fn (array $line): bool => in_array((string) $line['id'], $scopedItemIds, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    private function resolveScopedMenuItemIds(string $type, array $config, array $conditions): array
    {
        $configIds = $this->normalizeStringList($config['menuItemIds'] ?? []);
        if ($configIds !== []) {
            return $configIds;
        }

        if (in_array($type, [Promotion::TYPE_PERCENTAGE_ITEMS, Promotion::TYPE_BUY_X_GET_Y], true)) {
            return $this->normalizeStringList($conditions['menuItemIds'] ?? []);
        }

        return [];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $item): string => (string) $item,
            array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== ''),
        )));
    }
}
