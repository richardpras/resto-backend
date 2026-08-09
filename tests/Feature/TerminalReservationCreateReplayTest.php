<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Terminals\Support\TerminalOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDraftReservations;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TerminalReservationCreateReplayTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use CreatesDraftReservations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_sync_batch_replays_reservation_create(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'RSV Sync Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'rsv-sync-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        [$menuItem, $price] = $this->seedReservationMenuItem((int) $outlet->id, 80000);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'rsv-device-'.$outlet->id,
        ])->assertOk();

        $localRef = 'local:rsv-'.Str::uuid()->toString();
        $fp = hash('sha256', 'rsv-create-'.$localRef);

        $response = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'rsv-device-'.$outlet->id,
            'operations' => [[
                'fingerprint' => $fp,
                'operationType' => TerminalOperationType::RESERVATION_CREATE,
                'payload' => [
                    'outletId' => (int) $outlet->id,
                    'customerName' => 'Offline Guest',
                    'customerPhone' => '081234',
                    'partySize' => 2,
                    'reservationAt' => now()->addDay()->toISOString(),
                    'items' => [['menuItemId' => $menuItem->id, 'qty' => 2]],
                    'clientLocalRef' => $localRef,
                ],
                'clientOccurredAt' => now()->toISOString(),
            ]],
        ])->assertOk();

        $response->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.outcomeSummary.entity', 'reservation')
            ->assertJsonPath('data.results.0.outcomeSummary.clientLocalRef', $localRef)
            ->assertJsonPath('data.results.0.outcomeSummary.status', 'pending_deposit');

        $requiredDeposit = $response->json('data.results.0.outcomeSummary.requiredDepositAmount');
        $this->assertEquals($price * 2 * 0.5, (float) $requiredDeposit);

        $reservationId = (int) $response->json('data.results.0.outcomeSummary.reservationId');
        $this->assertGreaterThan(0, $reservationId);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'pending_deposit',
            'customer_name' => 'Offline Guest',
        ]);
    }
}
