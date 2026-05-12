<?php

namespace Tests\Unit\Modules\Payments;

use App\Modules\Payments\Providers\XenditQrisProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XenditQrisProviderTest extends TestCase
{
    public function test_create_transaction_returns_direct_qris_payload(): void
    {
        Http::fake([
            'https://api.xendit.co/qr_codes' => Http::response([
                'id' => 'qr_123',
                'external_id' => 'ext-123',
                'status' => 'ACTIVE',
                'type' => 'DYNAMIC',
                'qr_string' => '000201010212TESTQR',
                'updated' => now()->addMinutes(15)->toIso8601String(),
            ], 200),
        ]);

        $provider = new XenditQrisProvider([
            'secret_key' => 'xnd_test_key',
            'api_base_url' => 'https://api.xendit.co',
            'qris_callback_url' => 'https://merchant.local/api/v1/payments/webhooks/xendit',
            'http_timeout_seconds' => 10,
        ]);

        $result = $provider->createTransaction([
            'externalReference' => 'ext-123',
            'orderId' => 20,
            'outletId' => 1,
            'amount' => 12000,
            'currency' => 'IDR',
            'paymentMethod' => 'qris',
        ]);

        $this->assertSame('qris', $result['paymentMethod']);
        $this->assertSame('', (string) ($result['checkout_url'] ?? ''));
        $this->assertSame('000201010212TESTQR', $result['qr_string']);
        $this->assertSame('xendit', $result['provider_metadata']['provider']);
        $this->assertSame('direct_qris', $result['provider_metadata']['mode']);
    }

    public function test_non_qris_methods_fallback_to_invoice_flow(): void
    {
        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv_123',
                'external_id' => 'ext-999',
                'status' => 'PENDING',
                'invoice_url' => 'https://checkout.xendit.example/inv_123',
                'expiry_date' => now()->addMinutes(30)->toIso8601String(),
            ], 200),
        ]);

        $provider = new XenditQrisProvider([
            'secret_key' => 'xnd_test_key',
            'api_base_url' => 'https://api.xendit.co',
            'payer_email' => 'cashier@example.com',
            'http_timeout_seconds' => 10,
        ]);

        $result = $provider->createTransaction([
            'externalReference' => 'ext-999',
            'orderId' => 20,
            'outletId' => 1,
            'amount' => 25000,
            'currency' => 'IDR',
            'paymentMethod' => 'ewallet',
        ]);

        $this->assertSame('https://checkout.xendit.example/inv_123', $result['checkout_url']);
        $this->assertSame('pending', $result['status']);
    }
}

