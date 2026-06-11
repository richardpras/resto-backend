<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TableQrPdfExportTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_export_pdf_for_selected_tables(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required for QR PNG generation.');
        }

        [$user, $outlet] = $this->actAsAdminWithOutlet();
        SystemSetting::query()->updateOrCreate(['id' => 1], [
            'enable_split_bill' => true,
            'enable_multi_payment' => true,
            'confirm_before_payment' => true,
            'enable_qr_ordering' => true,
            'employee_self_service_enabled' => false,
            'customer_app_url' => 'https://order.example.com',
        ]);

        $tableA = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A01',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
            'qr_public_id' => 'TBL_EXP01',
            'qr_enabled' => true,
        ]);
        $tableB = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A02',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
            'qr_public_id' => 'TBL_EXP02',
            'qr_enabled' => true,
        ]);

        $query = http_build_query([
            'outletId' => $outlet->id,
            'tableIds' => [(int) $tableA->id, (int) $tableB->id],
        ]);
        $response = $this->get('/api/v1/tables/qr/export?'.$query);
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** @return array{0:\App\Models\User,1:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'QR PDF Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-pdf-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
