<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Token;
use Tests\Concerns\PassportAuthTestSetup;
use Tests\TestCase;

class AuthLogoutRevokesTokenTest extends TestCase
{
    use PassportAuthTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportAuth();
    }

    public function test_logout_revokes_bearer_token(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $token = (string) $login->json('data.accessToken');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertTrue(
            Token::query()->where('user_id', $user->id)->where('revoked', true)->exists(),
            'Expected logout to revoke the Passport access token row.',
        );

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
