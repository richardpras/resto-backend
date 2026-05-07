<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosSessionApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_pos_session_open_current_close_flow_per_outlet(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Main Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 150000,
            'notes' => 'Start shift',
        ])->assertCreated();

        $sessionId = (int) $open->json('data.id');
        self::assertGreaterThan(0, $sessionId);

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 100000,
        ])->assertUnprocessable();

        $this->getJson('/api/v1/pos-sessions/current?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.status', 'open');

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'closingCash' => 160000,
            'notes' => 'Close shift',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.cashVariance', 10000);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $sessionId,
            'status' => 'closed',
            'opening_cash' => 150000,
            'closing_cash' => 160000,
            'cash_variance' => 10000,
        ]);
    }

    public function test_pos_session_endpoints_are_outlet_scoped(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->createOutlet('Allowed Outlet');
        $forbiddenOutlet = $this->createOutlet('Forbidden Outlet');
        $this->assignUserToOutlets($user, [$allowedOutlet->id]);

        $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $forbiddenOutlet->id,
            'openingCash' => 50000,
        ])->assertUnprocessable();

        $open = $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $allowedOutlet->id,
            'openingCash' => 50000,
        ])->assertCreated();

        $sessionId = (int) $open->json('data.id');
        self::assertGreaterThan(0, $sessionId);

        $this->getJson('/api/v1/pos-sessions/current?outletId='.$forbiddenOutlet->id)
            ->assertUnprocessable();

        $otherSessionId = \DB::table('pos_sessions')->insertGetId([
            'outlet_id' => $forbiddenOutlet->id,
            'opened_by_user_id' => $user->id,
            'status' => 'open',
            'opening_cash' => 10000,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/pos-sessions/'.$otherSessionId.'/close', [
            'closingCash' => 12000,
        ])->assertNotFound();

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'closingCash' => 55000,
        ])->assertOk();
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'out-'.uniqid(),
        ]);
    }
}
