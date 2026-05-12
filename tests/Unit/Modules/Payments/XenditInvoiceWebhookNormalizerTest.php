<?php

namespace Tests\Unit\Modules\Payments;

use App\Modules\Payments\Webhooks\XenditInvoiceWebhookNormalizer;
use PHPUnit\Framework\TestCase;

class XenditInvoiceWebhookNormalizerTest extends TestCase
{
    public function test_normalizes_paid_invoice_payload(): void
    {
        $normalizer = new XenditInvoiceWebhookNormalizer;
        $out = $normalizer->normalize([
            'id' => 'inv_123',
            'external_id' => 'resto-order-1',
            'status' => 'PAID',
            'updated' => '2026-05-11T10:00:00.000Z',
            'payment_channel' => 'QRIS',
        ]);

        $this->assertSame('resto-order-1', $out['externalReference']);
        $this->assertSame('paid', $out['status']);
        $this->assertSame('xendit-invoice#inv_123#PAID', $out['eventId']);
        $this->assertSame('qris', $out['paymentMethod']);
        $this->assertIsArray($out['payload']);
    }

    public function test_cancelled_maps_to_failed_for_internal_transitions(): void
    {
        $out = XenditInvoiceWebhookNormalizer::fromInvoice([
            'id' => 'inv_9',
            'external_id' => 'ext-9',
            'status' => 'CANCELLED',
        ]);

        $this->assertSame('failed', $out['status']);
    }

    public function test_normalizes_qris_payment_callback_payload(): void
    {
        $normalizer = new XenditInvoiceWebhookNormalizer;
        $out = $normalizer->normalize([
            'id' => 'qrpy_123',
            'status' => 'COMPLETED',
            'updated' => '2026-05-12T02:00:00.000Z',
            'payment_detail_source' => 'GOPAY',
            'qr_code' => [
                'id' => 'qr_123',
                'external_id' => 'resto-order-qr-1',
                'type' => 'DYNAMIC',
            ],
        ]);

        $this->assertSame('resto-order-qr-1', $out['externalReference']);
        $this->assertSame('paid', $out['status']);
        $this->assertSame('xendit-qris-payment#qrpy_123#COMPLETED', $out['eventId']);
        $this->assertSame('qris', $out['paymentMethod']);
    }
}
