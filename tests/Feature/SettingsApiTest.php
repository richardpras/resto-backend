<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_unauthenticated_get_merchant_settings_returns_401(): void
    {
        $this->getJson('/api/v1/merchant-settings')->assertUnauthorized();
    }

    public function test_authenticated_user_without_settings_permission_cannot_read_merchant_settings(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/merchant-settings')->assertForbidden();
    }

    public function test_administrator_can_get_and_patch_merchant_settings(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $before = $this->getJson('/api/v1/merchant-settings')->assertOk()->json('data');

        $this->assertArrayHasKey('name', $before);
        $this->assertArrayHasKey('currency', $before);

        $payload = array_merge($before, ['name' => 'Updated Merchant Co']);

        $this->patchJson('/api/v1/merchant-settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Merchant Co');

        $this->getJson('/api/v1/merchant-settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Merchant Co');
    }

    public function test_administrator_can_list_outlets_with_data_envelope(): void
    {
        $admin = $this->actingAsUserManagementApiAdministrator();

        $outlet = Outlet::query()->create([
            'code' => 'settings-outlet-'.uniqid(),
            'name' => 'Settings Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);
        $this->assignUserToOutlets($admin, [$outlet->id]);

        $this->getJson('/api/v1/outlets?per_page=1&page=1')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Success')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $outlet->id);
    }
}
