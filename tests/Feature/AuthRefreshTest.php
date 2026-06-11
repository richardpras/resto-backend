<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PassportAuthTestSetup;
use Tests\TestCase;

class AuthRefreshTest extends TestCase
{
    use PassportAuthTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportAuth();
    }

    public function test_refresh_rotates_token_and_invalidates_previous(): void
    {
        User::factory()->create([
            'email' => 'manager@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'manager@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $oldToken = (string) $login->json('data.accessToken');

        $refresh = $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk();

        $newToken = (string) $refresh->json('data.accessToken');
        $this->assertNotSame($oldToken, $newToken);

        $this->withHeader('Authorization', 'Bearer '.$newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
