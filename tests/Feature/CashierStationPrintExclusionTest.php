<?php

namespace Tests\Feature;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\CashierStationValidationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashierStationPrintExclusionTest extends TestCase
{
    use RefreshDatabase;
    use CashierStationValidationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Bus::fake([ProcessPrintJob::class]);
    }

    public function test_mixed_order_excludes_cashier_station_from_kitchen_print_jobs(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionCashierValidationStations($outlet);
        $this->seedKitchenBarPrintRoutes($outlet, $stations);
        $items = $this->createCashierValidationMenuItems($outlet, $stations);

        $order = $this->createConfirmedCashierValidationOrder(
            $outlet,
            $items['nasi'],
            $items['esTeh'],
            $items['rokok'],
        );

        $jobs = PrintJob::query()
            ->where('outlet_id', $outlet->id)
            ->where('type', 'kitchen')
            ->get();

        $this->assertCount(2, $jobs);
        $this->assertFalse(
            $jobs->contains(fn (PrintJob $job): bool => data_get($job->route_snapshot, 'stationCode') === 'cashier'),
        );

        $allPrintedNames = $jobs
            ->flatMap(fn (PrintJob $job): array => collect($job->printable_snapshot['items'] ?? [])->pluck('name')->all())
            ->all();

        $this->assertEqualsCanonicalizing(['Nasi Goreng', 'Es Teh'], $allPrintedNames);
        $this->assertNotContains('Rokok Marlboro', $allPrintedNames);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Cashier Print Validation '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cashier-print-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}
