<?php

namespace Tests\Unit;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use App\Modules\PromotionEngine\Services\PromotionEvaluationService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromotionEvaluationServiceTest extends TestCase
{
    private PromotionEvaluationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PromotionEvaluationService;
    }

    public function test_percentage_order_discount(): void
    {
        $promotion = $this->makePromotion([
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10, 'maxDiscount' => null],
        ]);

        $result = $this->service->calculateDiscount($promotion, [], 100000);

        $this->assertSame(10000.0, $result['discountAmount']);
    }

    public function test_percentage_items_discount_only_on_eligible_lines(): void
    {
        $promotion = $this->makePromotion([
            'type' => Promotion::TYPE_PERCENTAGE_ITEMS,
            'config' => ['rate' => 10, 'menuItemIds' => ['101']],
        ]);

        $cart = [
            ['id' => '101', 'price' => 50000, 'qty' => 2],
            ['id' => '202', 'price' => 30000, 'qty' => 1],
        ];

        $result = $this->service->calculateDiscount($promotion, $cart, 130000);

        $this->assertSame(10000.0, $result['discountAmount']);
    }

    public function test_buy_x_get_y_discount(): void
    {
        $promotion = $this->makePromotion([
            'type' => Promotion::TYPE_BUY_X_GET_Y,
            'config' => ['buyQty' => 1, 'getQty' => 1, 'menuItemIds' => ['101']],
        ]);

        $cart = [
            ['id' => '101', 'price' => 25000, 'qty' => 2],
        ];

        $result = $this->service->calculateDiscount($promotion, $cart, 50000);

        $this->assertSame(25000.0, $result['discountAmount']);
        $this->assertCount(1, $result['appliedItems']);
    }

    public function test_fixed_amount_capped_at_subtotal(): void
    {
        $promotion = $this->makePromotion([
            'type' => Promotion::TYPE_FIXED_AMOUNT,
            'config' => ['amount' => 50000],
        ]);

        $result = $this->service->calculateDiscount($promotion, [], 30000);

        $this->assertSame(30000.0, $result['discountAmount']);
    }

    public function test_min_spend_condition_blocks_eligibility(): void
    {
        $promotion = $this->makePromotion([
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10],
            'conditions' => ['minSpend' => 100000],
        ]);

        $eligible = $this->service->isEligible($promotion, [], 50000);

        $this->assertFalse($eligible);
    }

    public function test_pick_best_uses_priority_then_discount(): void
    {
        $lowPriority = $this->makePromotion([
            'id' => 1,
            'type' => Promotion::TYPE_FIXED_AMOUNT,
            'config' => ['amount' => 50000],
            'priority' => 1,
        ]);
        $highPriority = $this->makePromotion([
            'id' => 2,
            'type' => Promotion::TYPE_FIXED_AMOUNT,
            'config' => ['amount' => 10000],
            'priority' => 10,
        ]);

        $best = $this->service->pickBest(collect([$lowPriority, $highPriority]), [], 100000);

        $this->assertNotNull($best);
        $this->assertSame(2, $best['promotionId']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePromotion(array $overrides = []): Promotion
    {
        $promotion = new Promotion(array_merge([
            'outlet_id' => 1,
            'code' => 'TEST',
            'name' => 'Test Promo',
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10],
            'conditions' => [],
            'priority' => 0,
            'is_active' => true,
            'valid_from' => Carbon::parse('2026-01-01'),
            'valid_until' => Carbon::parse('2026-12-31'),
        ], $overrides));

        if (isset($overrides['id'])) {
            $promotion->id = $overrides['id'];
        }

        return $promotion;
    }
}
