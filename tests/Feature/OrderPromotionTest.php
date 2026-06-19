<?php

namespace Tests\Feature;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderPromotionTest extends TestCase
{
    use OrderVoucherTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_apply_percentage_promotion_on_walk_in_order(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet, [
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10, 'maxDiscount' => null],
        ]);
        $orderId = $this->createWalkInOrder($outlet);

        $this->postJson("/api/v1/orders/{$orderId}/promotions", [
            'promotionId' => $promotion->id,
        ])
            ->assertOk()
            ->assertJsonPath('preview.subtotal', 250000)
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('preview.subtotalAfterDiscount', 225000)
            ->assertJsonPath('preview.tax', 22500)
            ->assertJsonPath('preview.total', 247500)
            ->assertJsonPath('preview.balanceDue', 247500)
            ->assertJsonPath('data.promotionDiscount', 25000)
            ->assertJsonPath('data.tax', 22500)
            ->assertJsonPath('data.total', 247500);

        $this->assertDatabaseHas('order_promotions', [
            'order_id' => $orderId,
            'promotion_id' => $promotion->id,
            'discount_amount' => 25000,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount_amount' => 25000,
            'tax' => 22500,
            'total' => 247500,
            'balance_due' => 247500,
        ]);
    }

    public function test_apply_buy_x_get_y_promotion(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet, [
            'code' => 'BOGO',
            'type' => Promotion::TYPE_BUY_X_GET_Y,
            'config' => ['buyQty' => 1, 'getQty' => 1, 'menuItemIds' => ['101']],
        ]);
        $orderId = $this->createWalkInOrder($outlet, [
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 2, 'price' => 250000],
            ],
            'subtotal' => 500000,
            'tax' => 50000,
            'total' => 550000,
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/promotions", [
            'promotionId' => $promotion->id,
        ])
            ->assertOk()
            ->assertJsonPath('preview.discount', 250000);
    }

    public function test_remove_promotion(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet);
        $orderId = $this->createWalkInOrder($outlet);

        $this->postJson("/api/v1/orders/{$orderId}/promotions", [
            'promotionId' => $promotion->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/orders/{$orderId}/promotions")
            ->assertOk()
            ->assertJsonPath('preview.discount', 0)
            ->assertJsonPath('preview.tax', 25000)
            ->assertJsonPath('preview.total', 275000);

        $this->assertDatabaseMissing('order_promotions', [
            'order_id' => $orderId,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount_amount' => 0,
            'tax' => 25000,
            'total' => 275000,
        ]);
    }

    public function test_promotion_and_voucher_are_mutually_exclusive(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $promotion = $this->createPromotion($outlet);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/promotions", [
            'promotionId' => $promotion->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertUnprocessable();
    }

    public function test_evaluate_endpoint_returns_best_candidate(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $this->createPromotion($outlet, [
            'code' => 'LOW',
            'type' => Promotion::TYPE_FIXED_AMOUNT,
            'config' => ['amount' => 10000],
            'priority' => 1,
        ]);
        $high = $this->createPromotion($outlet, [
            'code' => 'HIGH',
            'type' => Promotion::TYPE_FIXED_AMOUNT,
            'config' => ['amount' => 50000],
            'priority' => 10,
        ]);

        $this->postJson('/api/v1/promotions/evaluate', [
            'outletId' => $outlet->id,
            'subtotal' => 250000,
            'items' => [
                ['id' => '101', 'price' => 250000, 'qty' => 1],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('best.promotionId', $high->id)
            ->assertJsonPath('best.discountAmount', 50000);
    }

    public function test_daily_usage_limit_blocks_second_application(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet, [
            'conditions' => ['usageLimitPerDay' => 1],
        ]);
        $firstOrderId = $this->createWalkInOrder($outlet);
        $secondOrderId = $this->createWalkInOrder($outlet, ['code' => 'OV-'.uniqid()]);

        $this->postJson("/api/v1/orders/{$firstOrderId}/promotions", [
            'promotionId' => $promotion->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$secondOrderId}/promotions", [
            'promotionId' => $promotion->id,
        ])->assertUnprocessable();
    }

    public function test_sync_recomputes_promotion_after_order_items_change(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet, [
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10, 'maxDiscount' => null],
        ]);
        $orderId = $this->createWalkInOrder($outlet, [
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 2, 'price' => 250000],
            ],
            'subtotal' => 500000,
            'tax' => 50000,
            'total' => 550000,
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/promotions", [
            'promotionId' => $promotion->id,
        ])->assertOk()
            ->assertJsonPath('preview.discount', 50000);

        $this->patchJson("/api/v1/orders/{$orderId}", [
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 250000],
            ],
            'subtotal' => 250000,
            'tax' => 25000,
            'total' => 275000,
        ])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}/promotion-preview")
            ->assertOk()
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('preview.tax', 22500)
            ->assertJsonPath('preview.total', 247500);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount_amount' => 25000,
            'tax' => 22500,
            'total' => 247500,
        ]);
    }

    public function test_apply_promotion_by_code(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $promotion = $this->createPromotion($outlet, ['code' => 'SUMMER10']);
        $orderId = $this->createWalkInOrder($outlet);

        $this->postJson("/api/v1/orders/{$orderId}/promotions/by-code", [
            'code' => 'summer10',
        ])
            ->assertOk()
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('data.total', 247500)
            ->assertJsonPath('data.balanceDue', 247500);

        $this->assertDatabaseHas('order_promotions', [
            'order_id' => $orderId,
            'promotion_id' => $promotion->id,
        ]);
    }

    public function test_apply_promotion_by_code_invalid(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $orderId = $this->createWalkInOrder($outlet);

        $this->postJson("/api/v1/orders/{$orderId}/promotions/by-code", [
            'code' => 'NOTEXIST',
        ])->assertUnprocessable();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPromotion(Outlet $outlet, array $overrides = []): Promotion
    {
        return Promotion::query()->create(array_merge([
            'outlet_id' => $outlet->id,
            'code' => 'PROMO'.random_int(100, 999),
            'name' => 'Test Promotion',
            'type' => Promotion::TYPE_PERCENTAGE_ORDER,
            'config' => ['rate' => 10, 'maxDiscount' => null],
            'conditions' => [],
            'priority' => 5,
            'is_combinable' => false,
            'exclusive' => false,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createWalkInOrder(Outlet $outlet, array $overrides = []): int
    {
        $response = $this->postJson('/api/v1/orders', $this->orderVoucherOrderPayload(array_merge([
            'outletId' => $outlet->id,
            'code' => 'OP-'.uniqid(),
            'idempotencyKey' => 'promo-test-'.uniqid(),
        ], $overrides)));

        $response->assertSuccessful();

        return (int) $response->json('data.id');
    }
}
