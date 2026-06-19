<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MemberLoyaltyAccountLinkTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_member_auto_links_loyalty_account(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('MLK');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->postJson('/api/v1/members', [
            'outletId' => (int) $outlet->id,
            'name' => 'Linked Member',
            'phone' => '081234567890',
            'email' => 'linked@example.com',
            'status' => 'active',
        ])->assertCreated();

        $memberId = (int) $response->json('data.id');
        $loyaltyAccountId = (int) $response->json('data.loyaltyAccountId');

        $this->assertGreaterThan(0, $loyaltyAccountId);
        $this->assertDatabaseHas('members', [
            'id' => $memberId,
            'loyalty_account_id' => $loyaltyAccountId,
        ]);
        $this->assertDatabaseHas('loyalty_accounts', [
            'id' => $loyaltyAccountId,
            'phone' => '081234567890',
            'name' => 'Linked Member',
        ]);
    }

    public function test_create_member_links_existing_loyalty_account_by_phone(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('MLK2');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $account = LoyaltyAccount::query()->create([
            'outlet_id' => (int) $outlet->id,
            'customer_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'global_customer_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Existing CRM',
            'phone' => '081300000001',
            'points_balance' => 55,
            'lifetime_points_earned' => 55,
            'lifetime_points_redeemed' => 0,
            'lifetime_spend' => 0,
            'lifetime_visits' => 1,
            'last_activity_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/members', [
            'outletId' => (int) $outlet->id,
            'name' => 'Existing CRM',
            'phone' => '081300000001',
            'status' => 'active',
        ])->assertCreated();

        $this->assertSame((int) $account->id, (int) $response->json('data.loyaltyAccountId'));
        $this->assertSame(55, (int) $response->json('data.crmPointsBalance'));
    }

    public function test_update_member_syncs_profile_to_linked_account(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('MLK3');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/members', [
            'outletId' => (int) $outlet->id,
            'name' => 'Before Name',
            'phone' => '081400000001',
            'status' => 'active',
        ])->assertCreated();

        $memberId = (int) $create->json('data.id');
        $loyaltyAccountId = (int) $create->json('data.loyaltyAccountId');

        $this->patchJson("/api/v1/members/{$memberId}", [
            'name' => 'After Name',
            'phone' => '081400000099',
            'email' => 'after@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('loyalty_accounts', [
            'id' => $loyaltyAccountId,
            'name' => 'After Name',
            'phone' => '081400000099',
            'email' => 'after@example.com',
        ]);
    }

    public function test_lookup_member_by_loyalty_account_id(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('MLK4');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/members', [
            'outletId' => (int) $outlet->id,
            'name' => 'Lookup Member',
            'phone' => '081500000001',
            'status' => 'active',
        ])->assertCreated();

        $loyaltyAccountId = (int) $create->json('data.loyaltyAccountId');
        $memberId = (int) $create->json('data.id');

        $this->getJson("/api/v1/members/by-loyalty-account/{$loyaltyAccountId}")
            ->assertOk()
            ->assertJsonPath('data.memberId', (string) $memberId);
    }

    public function test_backfill_command_links_unlinked_members(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'Backfill Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'bf-'.uniqid(),
        ]);

        Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'MEM-00001',
            'full_name' => 'Legacy Member',
            'phone' => '081600000001',
            'is_active' => true,
            'points' => 0,
        ]);

        Artisan::call('members:link-loyalty-accounts', ['--outletId' => (int) $outlet->id]);

        $member = Member::query()->where('phone', '081600000001')->first();
        $this->assertNotNull($member?->loyalty_account_id);
        $this->assertDatabaseHas('loyalty_accounts', [
            'id' => $member->loyalty_account_id,
            'phone' => '081600000001',
        ]);
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
