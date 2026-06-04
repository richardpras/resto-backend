<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class OvertimeTypeTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_and_list_overtime_types(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        $this->postJson('/api/v1/overtime-types', [
            'outletId' => $outlet->id,
            'code' => 'holiday',
            'name' => 'Holiday OT',
            'multiplier' => 2.0,
        ])->assertCreated();

        $this->getJson('/api/v1/overtime-types?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.multiplier', 2);
    }

    public function test_duplicate_code_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        $outlet = $this->seedOutlet();

        OvertimeType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'regular',
            'name' => 'Regular',
            'multiplier' => 1.5,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/overtime-types', [
            'outletId' => $outlet->id,
            'code' => 'regular',
            'name' => 'Dup',
        ])->assertStatus(422);
    }

    private function seedOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'OT Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ot-out',
        ]);
    }
}
