<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\TestCase;

class PrinterSettingsBarDessertTypeTest extends TestCase
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

    public function test_printer_store_accepts_bar_and_dessert_types(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Printer Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pr-'.uniqid('', true),
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        foreach (['bar', 'dessert'] as $type) {
            $this->postJson('/api/v1/printers', [
                'id' => 'printer-'.$type,
                'name' => ucfirst($type).' Printer',
                'printerType' => $type,
                'connection' => 'lan',
                'ip' => '10.0.0.10',
                'outletId' => $outlet->id,
            ])->assertCreated()
                ->assertJsonPath('data.printerType', $type);
        }
    }

    public function test_printer_store_rejects_unknown_type(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Printer Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pr-'.uniqid('', true),
        ]);

        $user = $this->createUserWithPermission('settings.update', $outlet);
        Passport::actingAs($user);

        $this->postJson('/api/v1/printers', [
            'id' => 'printer-invalid',
            'name' => 'Invalid Printer',
            'printerType' => 'bakery',
            'connection' => 'lan',
            'ip' => '10.0.0.11',
            'outletId' => $outlet->id,
        ])->assertUnprocessable();
    }
}
