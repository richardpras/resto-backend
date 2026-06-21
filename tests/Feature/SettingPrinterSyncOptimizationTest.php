<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class SettingPrinterSyncOptimizationTest extends TestCase
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

    public function test_patch_printer_without_route_changes_preserves_route_ids(): void
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

        $syncResponse = $this->patchJson('/api/v1/printers/kitchen-printer-1', [
            'name' => 'Kitchen Printer',
            'printerType' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.50',
            'outletId' => $outlet->id,
        ])->assertOk();

        $profileId = (int) $syncResponse->json('data.printerProfileId');
        $routeIdsAfterFirstSync = PrinterRoute::query()
            ->where('printer_profile_id', $profileId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertCount(1, $routeIdsAfterFirstSync);

        $this->patchJson('/api/v1/printers/kitchen-printer-1', [
            'name' => 'Kitchen Printer Updated',
            'printerType' => 'kitchen',
            'connection' => 'lan',
            'ip' => '10.0.0.50',
            'outletId' => $outlet->id,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Kitchen Printer Updated');

        $routeIdsAfterSecondSync = PrinterRoute::query()
            ->where('printer_profile_id', $profileId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($routeIdsAfterFirstSync, $routeIdsAfterSecondSync);
    }
}
