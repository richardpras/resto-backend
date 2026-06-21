<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class SettingPrinterPaperWidthTest extends TestCase
{
    use RefreshDatabase;
    use ProductionStationTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(UserManagementPermissionsSeeder::class);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_printer_syncs_58mm_width_to_profile_meta(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $response = $this->postJson('/api/v1/printers', [
            'id' => 'paper-width-58',
            'name' => 'Kasir 58',
            'printerType' => 'cashier',
            'connection' => 'lan',
            'thermalPaperWidth' => '58mm',
            'ip' => '10.0.0.58',
            'outletId' => $outlet->id,
        ])->assertCreated();

        $profileId = (int) $response->json('data.printerProfileId');
        $profile = PrinterProfile::query()->findOrFail($profileId);

        $this->assertSame('58mm', data_get($profile->meta, 'print.thermalPaperWidth'));
        $this->assertSame(32, data_get($profile->meta, 'print.thermalWidthChars'));
        $this->assertSame('58mm', $response->json('data.thermalPaperWidth'));
    }

    public function test_update_printer_syncs_80mm_width_to_profile_meta(): void
    {
        $outlet = $this->createOutlet();

        SettingPrinter::query()->create([
            'id' => 'paper-width-update',
            'name' => 'Kitchen Printer',
            'printer_type' => 'kitchen',
            'connection' => 'lan',
            'thermal_paper_width' => '58mm',
            'ip' => '10.0.0.60',
            'bluetooth_device' => null,
            'outlet_id' => $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $syncResponse = $this->patchJson('/api/v1/printers/paper-width-update', [
            'name' => 'Kitchen Printer',
            'printerType' => 'kitchen',
            'connection' => 'lan',
            'thermalPaperWidth' => '58mm',
            'ip' => '10.0.0.60',
            'outletId' => $outlet->id,
        ])->assertOk();

        $profileId = (int) $syncResponse->json('data.printerProfileId');

        $this->patchJson('/api/v1/printers/paper-width-update', [
            'name' => 'Kitchen Printer',
            'printerType' => 'kitchen',
            'connection' => 'lan',
            'thermalPaperWidth' => '80mm',
            'ip' => '10.0.0.60',
            'outletId' => $outlet->id,
        ])->assertOk()
            ->assertJsonPath('data.thermalPaperWidth', '80mm');

        $profile = PrinterProfile::query()->findOrFail($profileId);
        $this->assertSame('80mm', data_get($profile->meta, 'print.thermalPaperWidth'));
        $this->assertSame(42, data_get($profile->meta, 'print.thermalWidthChars'));
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Paper Width Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pw-'.uniqid('', true),
        ]);
    }
}
