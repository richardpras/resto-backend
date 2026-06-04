<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotificationTemplate;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletBrevoSetting;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use App\Modules\LoyaltyEngine\Services\LoyaltyNotificationService;
use App\Modules\LoyaltyEngine\Services\LoyaltyRedeemService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

trait LoyaltyNotificationTestFixtures
{
    protected function actingAsNotificationManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_notification_admin__'],
            ['description' => 'Members manage'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    protected function createNotificationOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Notification Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lnotify-'.$suffix.uniqid(),
        ]);
    }

    protected function createNotificationMember(int $outletId, string $label, ?string $email = null): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => $label,
            'name' => $label,
            'phone' => '08'.random_int(1000000000, 9999999999),
            'email' => $email,
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    protected function seedSpendProgram(int $outletId): LoyaltyProgram
    {
        return LoyaltyProgram::query()->create([
            'outlet_id' => $outletId,
            'code' => 'SPEND-'.uniqid(),
            'name' => 'Spend Program',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
            'effective_from' => now()->subDay()->toDateString(),
        ]);
    }

    protected function grantMemberPoints(int $memberId, int $programId, int $points): void
    {
        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);
        $result = $ledger->createEarnFromOrder($memberId, $programId, random_int(100000, 999999), $points);
        if ($result['created']) {
            $projection->applyLedgerEntry($result['entry']);
        }
    }

    protected function createNotificationTemplate(
        int $outletId,
        string $eventType,
        string $channel,
        string $subject,
        string $content,
    ): LoyaltyNotificationTemplate {
        return LoyaltyNotificationTemplate::query()->create([
            'outlet_id' => $outletId,
            'code' => strtoupper($eventType.'_'.$channel),
            'name' => $eventType.' template',
            'event_type' => $eventType,
            'channel' => $channel,
            'subject' => $subject,
            'content' => $content,
            'is_active' => true,
        ]);
    }

    protected function configureOutletBrevo(int $outletId, string $apiKey = 'test-brevo-key'): OutletBrevoSetting
    {
        return OutletBrevoSetting::query()->updateOrCreate(
            ['outlet_id' => $outletId],
            [
                'api_key' => $apiKey,
                'sender_email' => 'loyalty@example.com',
                'sender_name' => 'Loyalty',
                'is_enabled' => true,
            ],
        );
    }

    protected function dispatchPointEarned(int $outletId, int $memberId, int $points): void
    {
        app(LoyaltyNotificationService::class)->dispatchPointsEarned($outletId, $memberId, $points);
    }

    protected function fakeSuccessfulBrevo(): void
    {
        Http::fake([
            'api.brevo.com/*' => Http::response(['messageId' => 'test-message-id'], 201),
        ]);
    }
}
