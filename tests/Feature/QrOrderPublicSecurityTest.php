<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesQrGuestSession;
use Tests\TestCase;

class QrOrderPublicSecurityTest extends TestCase
{
    use CreatesQrGuestSession;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_public_lookup_does_not_expose_sensitive_fields(): void
    {
        $requestCode = $this->createRequest();

        $response = $this->getJson('/api/v1/public/qr-orders/'.$requestCode)->assertOk();
        $payload = json_encode($response->json('data'), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('confirmed_by_user_id', $payload);
        $this->assertStringNotContainsString('rejected_by_user_id', $payload);
        $this->assertStringNotContainsString('rejectionReason', $payload);
        $this->assertStringNotContainsString('orderId', $payload);
        $this->assertStringNotContainsString('outletId', $payload);
        $this->assertStringNotContainsString('tableId', $payload);
        $this->assertStringNotContainsString('audit', $payload);

        $response->assertJsonMissingPath('data.id');
        $response->assertJsonMissingPath('data.customerName');
        $response->assertJsonMissingPath('data.rejectionReason');
        $response->assertJsonMissingPath('data.orderId');
    }

    public function test_raw_outlet_and_table_submit_without_guest_session_is_rejected(): void
    {
        $ctx = $this->seedQrGuestOrderingContext();

        $this->postJson('/api/v1/qr-orders', [
            'outletId' => $ctx['outlet']->id,
            'tableId' => $ctx['table']->id,
            'customerName' => 'Guest',
            'items' => [['menuItemId' => $ctx['menuItem']->id, 'qty' => 1]],
        ])->assertStatus(422);
    }

    private function createRequest(): string
    {
        $ctx = $this->seedQrGuestOrderingContext();

        return (string) $this->postQrOrderRequest($ctx)->json('data.requestCode');
    }
}
