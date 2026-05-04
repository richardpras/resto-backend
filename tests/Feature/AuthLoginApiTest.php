<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthLoginApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Tests Personal Access Client',
            '--provider' => 'users',
            '--no-interaction' => true,
        ]);
    }

    public function test_login_returns_401_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_login_returns_passport_bearer_payload_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'secret123',
        ])->assertOk();

        $response->assertJsonStructure([
            'message',
            'data' => [
                'accessToken',
                'tokenType',
                'expiresIn',
                'expiresAt',
                'user' => ['id', 'name', 'email'],
            ],
        ]);

        $this->assertNotEmpty($response->json('data.accessToken'));
        $this->assertSame('Bearer', $response->json('data.tokenType'));
        $this->assertTrue(
            $response->json('data.expiresIn') === null || is_int($response->json('data.expiresIn')),
        );
    }
}
