<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PassportAuthTestSetup;
use Tests\TestCase;

class AuthSessionLifetimeTest extends TestCase
{
    use PassportAuthTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportAuth();
        config(['passport.personal_access_token_expire_minutes' => 1440]);
    }

    public function test_login_token_expires_in_about_twenty_four_hours(): void
    {
        User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $expiresIn = $response->json('data.expiresIn');
        $this->assertIsInt($expiresIn);
        $this->assertGreaterThanOrEqual(23 * 60 * 60, $expiresIn);
        $this->assertLessThanOrEqual(24 * 60 * 60, $expiresIn);
    }
}
