<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PaymentHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_health_endpoint_returns_payload(): void
    {
        Config::set('payments.providers.xendit.secret_key', 'xnd_secret');
        Config::set('payments.providers.xendit.webhook_token', 'wh_token');
        Config::set('payments.providers.xendit.qris_callback_url', 'https://api.example.com/webhooks/xendit');
        Config::set('payments.default_provider', 'xendit');

        $user = $this->actingAsSettingsManager();

        $response = $this->getJson('/api/v1/payments/health?provider=xendit');

        $response->assertOk()
            ->assertJsonPath('data.provider', 'xendit')
            ->assertJsonPath('data.healthy', true)
            ->assertJsonStructure([
                'data' => [
                    'provider',
                    'healthy',
                    'status',
                    'mode',
                    'missing',
                    'warnings',
                ],
            ]);
    }

    public function test_health_endpoint_reports_missing_credentials(): void
    {
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', '');
        Config::set('payments.providers.xendit.webhook_token', '');
        Config::set('payments.providers.xendit.qris_callback_url', '');

        $user = $this->actingAsSettingsManager();

        $response = $this->getJson('/api/v1/payments/health?provider=xendit');

        $response->assertOk()
            ->assertJsonPath('data.healthy', false)
            ->assertJsonFragment(['XENDIT_SECRET_KEY'])
            ->assertJsonFragment(['XENDIT_WEBHOOK_TOKEN']);
    }

    public function test_health_endpoint_requires_settings_manage_permission(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_pos_only__'],
            ['description' => 'POS only'],
        );
        $posPermission = Permission::query()->where('code', 'pos.use')->firstOrFail();
        $role->permissions()->sync([$posPermission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        $this->getJson('/api/v1/payments/health')->assertForbidden();
    }

    private function actingAsSettingsManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_settings_manager__'],
            ['description' => 'Settings manager'],
        );
        $permission = Permission::query()->where('code', 'settings.manage')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }
}
