<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\EssPortalFixture;
use Tests\TestCase;

class EmployeePortalAuthTest extends TestCase
{
    use EssPortalFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->setupEssPassport();
    }

    public function test_login_logout_and_me(): void
    {
        $this->enableEssPortal();
        [, $user] = $this->seedEmployeePortalUser();

        $login = $this->postJson('/api/v1/ess/login', [
            'email' => 'ess.worker@test.local',
            'password' => 'secret123',
        ])->assertOk();

        $token = $login->json('data.accessToken');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ess/me')
            ->assertOk()
            ->assertJsonPath('data.permissionCodes.0', 'employee.portal')
            ->assertJsonPath('data.employeeId', (int) $user->employee_id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ess/logout')
            ->assertOk();
    }

    public function test_disabled_portal_blocks_login(): void
    {
        $this->disableEssPortal();
        $this->seedEmployeePortalUser();

        $this->postJson('/api/v1/ess/login', [
            'email' => 'ess.worker@test.local',
            'password' => 'secret123',
        ])->assertStatus(403);
    }

    public function test_invalid_credentials_rejected(): void
    {
        $this->enableEssPortal();
        $this->seedEmployeePortalUser();

        $this->postJson('/api/v1/ess/login', [
            'email' => 'ess.worker@test.local',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_authenticated_routes_require_token(): void
    {
        $this->enableEssPortal();

        $this->getJson('/api/v1/ess/dashboard')->assertStatus(401);
    }
}
