<?php

namespace Tests\Unit;

use App\Modules\Print\Services\ThermalReceiptLayoutBuilder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ThermalReceiptLayoutBuilderTest extends TestCase
{
    private ThermalReceiptLayoutBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(ThermalReceiptLayoutBuilder::class);
    }

    public function test_builds_v4_layout_with_cashier_discount_and_payment_rows(): void
    {
        $lines = $this->builder->buildCustomerReceipt([
            'order_code' => 'ORD-V4',
            'customer_display' => 'Budi',
            'paid_at' => '2026-06-21T14:32:00+07:00',
            'order_type' => 'Dine In',
            'cashier_name' => 'Siti',
            'split_label' => 'Guest A',
            'subtotal' => 45000.0,
            'tax' => 4500.0,
            'total' => 44500.0,
            'discount_lines' => [
                ['type' => 'promotion', 'label' => 'SAVE10', 'amount' => -5000.0],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => 30000.0, 'label' => 'Cash'],
                ['method' => 'qris', 'amount' => 14500.0, 'label' => 'QRIS'],
            ],
            'lines' => [
                ['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 45000.0],
            ],
            'receipt_branding' => [
                'outletName' => 'Mountain Cafe',
                'header' => '',
                'footer' => '',
                'showTaxBreakdown' => true,
            ],
        ], 32);

        $plain = implode("\n", $this->builder->toPlainThermalLines($lines, 32));

        $this->assertStringContainsString('Cashier', $plain);
        $this->assertStringContainsString('Siti', $plain);
        $this->assertStringContainsString('Split', $plain);
        $this->assertStringContainsString('Guest A', $plain);
        $this->assertStringContainsString('Promo (SAVE10)', $plain);
        $this->assertStringContainsString('Cash', $plain);
        $this->assertStringContainsString('QRIS', $plain);
        $this->assertStringNotContainsString('-- SPLIT --', $plain);
        $this->assertStringNotContainsString('SPLIT --', $plain);
    }

    public function test_builds_58mm_layout_with_meta_and_two_line_items(): void
    {
        Carbon::setTestNow('2026-06-21 14:32:00');

        $lines = $this->builder->buildCustomerReceipt([
            'order_code' => 'ORD-001',
            'customer_display' => 'Budi',
            'paid_at' => '2026-06-21T14:32:00+07:00',
            'order_type' => 'Dine In',
            'service_mode' => 'dine_in',
            'subtotal' => 45000.0,
            'tax' => 4500.0,
            'total' => 49500.0,
            'lines' => [
                ['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 45000.0],
            ],
            'receipt_branding' => [
                'outletName' => 'Mountain Cafe',
                'header' => 'Welcome',
                'footer' => 'Thank you',
                'showTaxBreakdown' => true,
            ],
        ], 32);

        $plain = $this->builder->toPlainThermalLines($lines, 32);
        $joined = implode("\n", $plain);

        $this->assertStringContainsString('Mountain Cafe', $joined);
        $this->assertStringContainsString('Order', $joined);
        $this->assertStringContainsString('ORD-001', $joined);
        $this->assertStringContainsString('Customer', $joined);
        $this->assertStringContainsString('Budi', $joined);
        $this->assertStringContainsString('Time', $joined);
        $this->assertStringContainsString('21/06/2026 14:32', $joined);
        $this->assertStringContainsString('Type', $joined);
        $this->assertStringContainsString('Dine In', $joined);
        $this->assertStringContainsString('Nasi Goreng', $joined);
        $this->assertStringContainsString('1 x 45,000.00', $joined);
        $this->assertStringContainsString('Subtotal', $joined);
        $this->assertStringContainsString('Tax', $joined);
        $this->assertStringContainsString('TOTAL', $joined);
        $this->assertStringContainsString('Thank you', $joined);
        $this->assertStringContainsString(str_repeat('-', 32), $joined);

        Carbon::setTestNow();
    }

    public function test_uses_guest_when_customer_missing(): void
    {
        $lines = $this->builder->buildCustomerReceipt([
            'order_code' => 'ORD-002',
            'order_type' => 'Takeaway',
            'subtotal' => 10000.0,
            'total' => 10000.0,
            'lines' => [],
            'receipt_branding' => [
                'outletName' => 'Cafe',
                'header' => '',
                'footer' => '',
                'showTaxBreakdown' => false,
            ],
        ], 32);

        $plain = implode("\n", $this->builder->toPlainThermalLines($lines, 32));

        $this->assertStringContainsString('Customer', $plain);
        $this->assertStringContainsString('Guest', $plain);
        $this->assertStringContainsString('Take Away', $plain);
    }

    public function test_80mm_divider_is_42_chars(): void
    {
        $lines = $this->builder->buildCustomerReceipt([
            'order_code' => 'ORD-080',
            'subtotal' => 0,
            'total' => 0,
            'lines' => [],
            'receipt_branding' => [
                'outletName' => 'Wide',
                'header' => '',
                'footer' => '',
                'showTaxBreakdown' => false,
            ],
        ], 42);

        $plain = implode("\n", $this->builder->toPlainThermalLines($lines, 42));

        $this->assertStringContainsString(str_repeat('-', 42), $plain);
    }

    public function test_trailing_feed_lines(): void
    {
        $feed = $this->builder->trailingFeedLines(3);

        $this->assertCount(3, $feed);
        $this->assertSame(' ', $feed[0]['text']);
    }

    public function test_build_customer_receipt_document_prepends_logo_raster_when_enabled(): void
    {
        $document = $this->builder->buildCustomerReceiptDocument([
            'order_code' => 'ORD-LOGO',
            'subtotal' => 10000.0,
            'total' => 10000.0,
            'lines' => [],
            'receipt_branding' => [
                'outletName' => 'Logo Cafe',
                'header' => '',
                'footer' => '',
                'showTaxBreakdown' => false,
                'showLogo' => true,
            ],
        ], 32, [
            'width' => 16,
            'height' => 16,
            'widthBytes' => 2,
            'rasterBase64' => base64_encode('abcd'),
        ]);

        $this->assertArrayHasKey('images', $document);
        $this->assertCount(1, $document['images']);
        $this->assertSame('center', $document['images'][0]['align']);
        $this->assertSame('abcd', base64_decode((string) $document['images'][0]['rasterBase64']));
    }
}
