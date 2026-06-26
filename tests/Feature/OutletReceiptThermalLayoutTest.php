<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Modules\Print\Services\ReceiptDocumentService;
use App\Modules\Print\Services\SettingPrinterSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OutletReceiptThermalLayoutTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Storage::fake('public');
    }

    public function test_customer_receipt_thermal_uses_outlet_receipt_settings(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Thermal Layout Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedReceiptSettings($outlet, showTaxBreakdown: true);
        $this->seedCashierPrinter($outlet, '58mm');

        $order = $this->createPaidOrder($outlet, subtotal: 45000, tax: 4500, total: 49500);

        $response = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => (int) $order->id,
            'issueFiscal' => false,
            'queuePrint' => false,
            'generatePdf' => false,
            'forceRegenerate' => false,
        ]);
        $response->assertOk();

        $history = ReceiptRenderHistory::query()->findOrFail((int) $response->json('data.id'));
        $thermal = (string) $history->thermal_text;

        $this->assertStringContainsString($outlet->name, $thermal);
        $this->assertStringContainsString('Terima kasih sudah mampir', $thermal);
        $this->assertStringContainsString('Sampai jumpa lagi', $thermal);
        $this->assertStringContainsString('Order', $thermal);
        $this->assertStringContainsString((string) $order->code, $thermal);
        $this->assertStringContainsString('Customer', $thermal);
        $this->assertStringContainsString('Guest', $thermal);
        $this->assertStringContainsString('Time', $thermal);
        $this->assertStringContainsString('Type', $thermal);
        $this->assertStringContainsString('Take Away', $thermal);
        $this->assertStringContainsString('Nasi Goreng', $thermal);
        $this->assertStringContainsString('1 x 45,000.00', $thermal);
        $this->assertStringContainsString('Subtotal', $thermal);
        $this->assertStringContainsString('PB1 10%', $thermal);
        $this->assertStringContainsString('TOTAL', $thermal);
        $this->assertStringNotContainsString('RECEIPT', $thermal);
        $this->assertStringNotContainsString('LOGO', $thermal);
        $this->assertStringNotContainsString('[LOGO]', $thermal);
        $this->assertThermalWidthAtMost($thermal, 32);
    }

    public function test_customer_receipt_hides_tax_line_when_show_tax_breakdown_disabled(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('No Tax Line Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedReceiptSettings($outlet, showTaxBreakdown: false);

        $order = $this->createPaidOrder($outlet, subtotal: 45000, tax: 4500, total: 49500);

        $response = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => (int) $order->id,
            'issueFiscal' => false,
            'queuePrint' => false,
            'generatePdf' => false,
            'forceRegenerate' => true,
        ]);
        $response->assertOk();

        $history = ReceiptRenderHistory::query()->findOrFail((int) $response->json('data.id'));
        $thermal = (string) $history->thermal_text;

        $this->assertStringContainsString('Subtotal', $thermal);
        $this->assertStringContainsString('TOTAL', $thermal);
        $this->assertDoesNotMatchRegularExpression('/^Tax/m', $thermal);
    }

    public function test_branding_fingerprint_changes_when_receipt_settings_update(): void
    {
        $outlet = $this->createOutlet('Fingerprint Outlet');
        $this->seedReceiptSettings($outlet, header: 'Header A');

        $service = app(ReceiptDocumentService::class);
        $first = $service->resolveReceiptBrandingFingerprint((int) $outlet->id);

        OutletReceiptSetting::query()->where('outlet_id', $outlet->id)->update([
            'receipt_header' => 'Header B',
        ]);

        $second = $service->resolveReceiptBrandingFingerprint((int) $outlet->id);

        $this->assertNotSame($first, $second);
    }

    public function test_queue_print_includes_thermal_document_with_logo_raster(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for outlet logo processing.');
        }

        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Logo Thermal Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $this->seedReceiptSettings($outlet, showTaxBreakdown: false);
        $this->seedCashierPrinter($outlet, '58mm');

        $this->postJson('/api/v1/outlets/'.$outlet->id.'/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 220, 220),
        ])->assertOk();

        $order = $this->createPaidOrder($outlet);

        Queue::fake();

        $response = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => (int) $order->id,
            'issueFiscal' => false,
            'queuePrint' => true,
            'generatePdf' => false,
            'forceRegenerate' => true,
        ]);
        $response->assertOk();

        $job = PrintJob::query()->latest('id')->first();
        $this->assertNotNull($job);
        $snapshot = is_array($job?->printable_snapshot) ? $job->printable_snapshot : [];
        $this->assertIsArray($snapshot['thermalDocument'] ?? null);
        $this->assertIsArray($snapshot['thermalDocument']['images'] ?? null);
        $this->assertNotEmpty($snapshot['thermalDocument']['images']);
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'code' => 'thermal-'.uniqid(),
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
    }

    private function seedReceiptSettings(Outlet $outlet, bool $showTaxBreakdown = true, string $header = 'Terima kasih sudah mampir'): void
    {
        OutletReceiptSetting::query()->create([
            'outlet_id' => $outlet->id,
            'receipt_header' => $header,
            'receipt_footer' => 'Sampai jumpa lagi',
            'show_logo' => true,
            'show_tax_breakdown' => $showTaxBreakdown,
        ]);
    }

    private function createPaidOrder(
        Outlet $outlet,
        float $subtotal = 45000,
        float $tax = 4500,
        float $total = 49500,
    ): Order {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'ORD-THERMAL-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'apply_tax' => $tax > 0,
            'tax_snapshot' => $tax > 0
                ? [['taxId' => 'tax-default', 'name' => 'PB1', 'type' => 'percentage', 'rate' => 10, 'inclusive' => false, 'amount' => $tax]]
                : null,
            'total' => $total,
            'paid_total' => $total,
            'balance_due' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_id' => 1,
            'name' => 'Nasi Goreng',
            'qty' => 1,
            'price' => $subtotal,
            'line_total' => $subtotal,
        ]);

        return $order->fresh(['items']);
    }

    private function seedCashierPrinter(Outlet $outlet, string $paperWidth = '58mm'): void
    {
        $setting = SettingPrinter::query()->create([
            'id' => 'cashier-thermal-'.uniqid(),
            'name' => 'Cashier Thermal',
            'printer_type' => 'cashier',
            'connection' => 'lan',
            'thermal_paper_width' => $paperWidth,
            'ip' => '10.0.0.70',
            'bluetooth_device' => null,
            'outlet_id' => $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);

        app(SettingPrinterSyncService::class)->syncFromSettingPrinter($setting->fresh() ?? $setting);
    }

    private function assertThermalWidthAtMost(string $thermal, int $maxWidth): void
    {
        foreach (preg_split("/\r\n|\n|\r/", $thermal) ?: [] as $line) {
            $trimmed = rtrim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            $this->assertLessThanOrEqual(
                $maxWidth,
                mb_strlen($trimmed),
                'Thermal line exceeds configured width: '.$trimmed,
            );
        }
    }
}
