<?php

namespace Tests\Unit;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletTaxAssignment;
use App\Models\Modules\Settings\Domain\Tax;
use App\Modules\Orders\Services\OrderTaxResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTaxResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_when_apply_tax_false(): void
    {
        $service = app(OrderTaxResolverService::class);
        $result = $service->resolve(1, 'dine_in', 'Dine-in', 100000, 0, false);

        $this->assertSame(0.0, $result['tax']);
        $this->assertSame(100000.0, $result['total']);
    }

    public function test_applies_assigned_outlet_tax_rule(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'OUT-TAX',
            'name' => 'Tax Outlet',
            'status' => 'active',
        ]);

        Tax::query()->create([
            'id' => 'tax-test',
            'name' => 'PB1',
            'type' => 'percentage',
            'value' => 10,
            'apply_dine_in' => true,
            'apply_takeaway' => true,
            'inclusive' => false,
            'status' => 'active',
        ]);

        OutletTaxAssignment::query()->create([
            'outlet_id' => $outlet->id,
            'tax_id' => 'tax-test',
        ]);

        $service = app(OrderTaxResolverService::class);
        $result = $service->resolve((int) $outlet->id, 'dine_in', 'Dine-in', 100000, 10000, true);

        $this->assertSame(9000.0, $result['tax']);
        $this->assertSame(99000.0, $result['total']);
        $this->assertCount(1, $result['taxLines']);
    }
}
