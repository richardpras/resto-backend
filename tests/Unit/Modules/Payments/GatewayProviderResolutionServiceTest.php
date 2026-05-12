<?php

namespace Tests\Unit\Modules\Payments;

use App\Modules\Payments\Services\GatewayProviderResolutionService;
use Tests\TestCase;

class GatewayProviderResolutionServiceTest extends TestCase
{
    public function test_outlet_override_applies_when_request_omits_provider(): void
    {
        config(['payments.default_provider' => 'midtrans']);
        config(['payments.outlet_overrides' => [
            '7' => ['default_provider' => 'xendit'],
        ]]);

        $service = new GatewayProviderResolutionService;

        $this->assertSame('xendit', $service->resolve(7, null));
        $this->assertSame('manual', $service->resolve(7, 'manual'));
    }
}
