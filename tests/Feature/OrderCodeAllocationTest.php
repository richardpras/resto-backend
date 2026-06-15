<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\InvoiceSequence;
use App\Models\Modules\Settings\Domain\NumberingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderCodeAllocationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_preview_returns_next_code_without_consuming_sequence(): void
    {
        $outlet = $this->seedOutlet();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        NumberingSetting::query()->create([
            'invoice_format' => 'INV-{0000}',
            'order_format' => 'ORD-{YYYY}{MM}{DD}-{000}',
        ]);

        $first = $this->getJson('/api/v1/orders/next-code?outletId='.$outlet->id)
            ->assertOk()
            ->json('data.code');
        $second = $this->getJson('/api/v1/orders/next-code?outletId='.$outlet->id)
            ->assertOk()
            ->json('data.code');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/ORD-\d{8}-001$/', (string) $first);
    }

    public function test_create_with_auto_allocates_sequential_codes(): void
    {
        $outlet = $this->seedOutlet();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        NumberingSetting::query()->create([
            'invoice_format' => 'INV-{0000}',
            'order_format' => 'ORD-{YYYY}{MM}{DD}-{000}',
        ]);

        $first = $this->postJson('/api/v1/orders', $this->orderPayload($outlet->id, 'AUTO', 1))->assertCreated()->json('data.code');
        $second = $this->postJson('/api/v1/orders', $this->orderPayload($outlet->id, 'AUTO', 2))->assertCreated()->json('data.code');

        $this->assertMatchesRegularExpression('/ORD-\d{8}-001$/', (string) $first);
        $this->assertMatchesRegularExpression('/ORD-\d{8}-002$/', (string) $second);
    }

    public function test_manual_code_is_preserved_when_not_auto(): void
    {
        $outlet = $this->seedOutlet();
        $user = $this->actingAsUserManagementApiAdministrator();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/orders', $this->orderPayload($outlet->id, 'CUSTOM-BILL-99'))
            ->assertCreated()
            ->assertJsonPath('data.code', 'CUSTOM-BILL-99');
    }

    public function test_daily_series_resets_counter_for_new_date_key(): void
    {
        $outlet = $this->seedOutlet();
        $dateKey = now()->format('Ymd');

        InvoiceSequence::query()->create([
            'outlet_id' => $outlet->id,
            'series_key' => 'ORD:'.$dateKey,
            'prefix' => 'ORD',
            'pad_length' => 3,
            'next_value' => 42,
        ]);

        $service = app(\App\Modules\Orders\Services\OrderCodeAllocationService::class);
        $code = $service->allocate((int) $outlet->id);

        $this->assertStringEndsWith('-042', $code);
        $this->assertSame(43, (int) InvoiceSequence::query()->where('series_key', 'ORD:'.$dateKey)->value('next_value'));
    }

    private function seedOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-'.uniqid(),
        ]);
    }

    /** @return array<string, mixed> */
    private function orderPayload(int $outletId, string $code, float $qty = 1): array
    {
        return [
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '1', 'name' => 'Nasi Goreng', 'qty' => $qty, 'price' => 30000],
            ],
            'subtotal' => 30000 * $qty,
            'tax' => 0,
            'total' => 30000 * $qty,
            'payments' => [],
            'confirmedAt' => now()->toISOString(),
        ];
    }
}
