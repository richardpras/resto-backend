<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class UserManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_unauthenticated_requests_to_user_role_permission_routes_return_401(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/roles')->assertUnauthorized();
        $this->getJson('/api/v1/permissions')->assertUnauthorized();
    }

    public function test_unauthenticated_non_json_request_redirects_to_login(): void
    {
        $this->get('/api/v1/users')->assertRedirect('/login');
    }

    public function test_authenticated_user_without_required_permission_cannot_list_users(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_authenticated_user_with_users_view_permission_can_list_users(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_auth_me_includes_roles_and_permission_codes(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $response = $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'email',
                    'roles',
                    'permissionCodes',
                ],
            ]);

        $codes = $response->json('data.permissionCodes');
        $this->assertIsArray($codes);
        $this->assertGreaterThanOrEqual(1, count($codes));
    }
}
