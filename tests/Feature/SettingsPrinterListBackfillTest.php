<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class SettingsPrinterListBackfillTest extends TestCase
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

    public function test_list_printers_does_not_backfill_missing_printer_profile_id(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Printer Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pr-'.uniqid('', true),
        ]);

        SettingPrinter::query()->create([
            'id' => 'kitchen-printer-1',
            'name' => 'Kitchen Printer',
            'printer_type' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.50',
            'bluetooth_device' => null,
            'outlet_id' => $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);

        $user = $this->createUserWithPermission('settings.view', $outlet);
        Passport::actingAs($user);

        $this->getJson('/api/v1/printers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Kitchen Printer')
            ->assertJsonPath('data.0.printerProfileId', null);

        $this->assertDatabaseHas('setting_printers', [
            'id' => 'kitchen-printer-1',
            'printer_profile_id' => null,
        ]);
    }

    public function test_update_printer_syncs_missing_printer_profile_id(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Printer Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pr-'.uniqid('', true),
        ]);

        SettingPrinter::query()->create([
            'id' => 'kitchen-printer-1',
            'name' => 'Kitchen Printer',
            'printer_type' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.50',
            'bluetooth_device' => null,
            'outlet_id' => $outlet->id,
            'assigned_categories' => null,
            'printer_profile_id' => null,
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $response = $this->patchJson('/api/v1/printers/kitchen-printer-1', [
            'name' => 'Kitchen Printer',
            'printerType' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.50',
            'outletId' => $outlet->id,
        ])->assertOk();

        $profileId = (int) $response->json('data.printerProfileId');
        $this->assertGreaterThan(0, $profileId);

        $this->assertDatabaseHas('setting_printers', [
            'id' => 'kitchen-printer-1',
            'printer_profile_id' => $profileId,
        ]);

        $this->assertDatabaseHas('printer_profiles', [
            'id' => $profileId,
            'outlet_id' => $outlet->id,
        ]);
    }
}
