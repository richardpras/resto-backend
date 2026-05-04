<?php

namespace Tests\Feature;

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

    public function test_unauthenticated_get_settings_returns_401(): void
    {
        $this->getJson('/api/v1/settings')->assertUnauthorized();
    }

    public function test_authenticated_user_without_settings_permission_cannot_read_settings(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/settings')->assertForbidden();
    }

    public function test_administrator_can_get_settings_with_expected_shape(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'merchant' => ['name', 'email', 'currency'],
                    'outlets',
                    'taxes',
                    'printers',
                    'paymentMethods',
                    'system',
                    'integration',
                    'numbering',
                    'banks',
                ],
            ]);
    }

    public function test_administrator_can_put_settings_and_read_back(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $before = $this->getJson('/api/v1/settings')->assertOk()->json('data');
        $payload = $before;
        $payload['merchant']['name'] = 'Updated Merchant Co';

        $this->putJson('/api/v1/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.merchant.name', 'Updated Merchant Co');

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.merchant.name', 'Updated Merchant Co');
    }
}
