<?php

namespace Tests\Feature;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase15LoyaltyFoundationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_loyalty_accrual_updates_balance_and_returns_idempotent_payload(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('LAC');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $customer = $this->postJson('/api/v1/customers', [
            'outletId' => (int) $outlet->id,
            'name' => 'Loyalty A',
            'phone' => '08111111111',
        ])->assertCreated()->json('data');

        $idempotencyKey = 'accrual-'.$outlet->id.'-001';
        $this->postJson('/api/v1/customers/'.$customer['id'].'/loyalty-ledger', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => $idempotencyKey,
            'pointsDelta' => 100,
            'spendAmount' => 50000,
            'visitIncrement' => 1,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account.pointsBalance', 100);

        $duplicate = $this->postJson('/api/v1/customers/'.$customer['id'].'/loyalty-ledger', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => $idempotencyKey,
            'pointsDelta' => 100,
            'spendAmount' => 50000,
            'visitIncrement' => 1,
        ])->assertCreated();

        $duplicate->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.account.pointsBalance', 100);
    }

    public function test_duplicate_redemption_is_idempotent_and_does_not_double_deduct_points(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('LRD');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $customerId = (int) $this->postJson('/api/v1/customers', [
            'outletId' => (int) $outlet->id,
            'name' => 'Redeemable',
            'phone' => '08222222222',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/customers/'.$customerId.'/loyalty-ledger', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'seed-'.$outlet->id,
            'pointsDelta' => 120,
        ])->assertCreated();

        $key = 'redeem-'.$outlet->id.'-001';
        $this->postJson('/api/v1/customers/'.$customerId.'/redeem', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => $key,
            'rewardCode' => 'FREE_DRINK',
            'pointsCost' => 50,
        ])->assertCreated()
            ->assertJsonPath('data.account.pointsBalance', 70);

        $dup = $this->postJson('/api/v1/customers/'.$customerId.'/redeem', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => $key,
            'rewardCode' => 'FREE_DRINK',
            'pointsCost' => 50,
        ])->assertCreated();

        $dup->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.account.pointsBalance', 70);
    }

    public function test_outlet_isolation_blocks_cross_outlet_customer_access(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->createOutletFixture('ALO');
        $blockedOutlet = $this->createOutletFixture('BLO');
        $this->assignUserToOutlets($user, [(int) $allowedOutlet->id]);

        $account = LoyaltyAccount::query()->create([
            'outlet_id' => (int) $blockedOutlet->id,
            'customer_uuid' => '11111111-1111-4111-8111-111111111111',
            'global_customer_uuid' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Blocked Existing',
            'phone' => '08123',
            'email' => null,
            'points_balance' => 0,
            'lifetime_points_earned' => 0,
            'lifetime_points_redeemed' => 0,
            'lifetime_spend' => 0,
            'lifetime_visits' => 0,
        ]);

        $this->getJson('/api/v1/customers/'.$account->id)
            ->assertStatus(404);
    }

    public function test_merge_moves_ledgers_redemptions_and_points_to_target_account(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('LMG');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $primaryId = (int) $this->postJson('/api/v1/customers', [
            'outletId' => (int) $outlet->id,
            'name' => 'Primary',
            'phone' => '08444444444',
        ])->assertCreated()->json('data.id');

        $secondaryId = (int) $this->postJson('/api/v1/customers', [
            'outletId' => (int) $outlet->id,
            'name' => 'Secondary',
            'phone' => '08555555555',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/customers/'.$secondaryId.'/loyalty-ledger', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'merge-seed-'.$outlet->id,
            'pointsDelta' => 40,
            'spendAmount' => 20000,
            'visitIncrement' => 1,
        ])->assertCreated();

        $this->postJson('/api/v1/customers/'.$secondaryId.'/redeem', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'merge-red-'.$outlet->id,
            'rewardCode' => 'SNACK',
            'pointsCost' => 10,
        ])->assertCreated();

        $this->postJson('/api/v1/customers/'.$secondaryId.'/merge', [
            'targetCustomerId' => $primaryId,
            'outletId' => (int) $outlet->id,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.target.id', $primaryId);

        $this->getJson('/api/v1/customers/'.$primaryId)
            ->assertOk()
            ->assertJsonPath('data.pointsBalance', 30);
    }

    public function test_stale_replay_is_rejected_at_loyalty_service_boundary(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('LST');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $customerId = (int) $this->postJson('/api/v1/customers', [
            'outletId' => (int) $outlet->id,
            'name' => 'Stale Replay',
            'phone' => '08666666666',
        ])->assertCreated()->json('data.id');

        $staleOccurredAt = CarbonImmutable::now()->utc()->subDays(60)->toIso8601String();
        $response = $this->postJson('/api/v1/customers/'.$customerId.'/loyalty-ledger', [
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'stale-'.$outlet->id,
            'pointsDelta' => 20,
            'clientOccurredAt' => $staleOccurredAt,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['clientOccurredAt']);
    }

    private function createOutletFixture(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }
}
