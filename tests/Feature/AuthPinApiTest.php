<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AuthPinApiTest extends TestCase
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

    public function test_me_includes_pin_set_flag(): void
    {
        $withPin = User::factory()->create([
            'email' => 'p1@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => '1111',
        ]);
        Passport::actingAs($withPin);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.pinSet', true);

        $noPin = User::factory()->create([
            'email' => 'p2@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => null,
        ]);
        Passport::actingAs($noPin);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.pinSet', false);
    }

    public function test_verify_screen_pin_rejects_when_not_set(): void
    {
        $user = User::factory()->create([
            'email' => 'np@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => null,
        ]);
        Passport::actingAs($user);

        $this->postJson('/api/v1/auth/verify-screen-pin', ['pin' => '1234'])
            ->assertUnprocessable();
    }

    public function test_verify_screen_pin_accepts_matching_pin(): void
    {
        $user = User::factory()->create([
            'email' => 'ok@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => '9876',
        ]);
        Passport::actingAs($user);

        $this->postJson('/api/v1/auth/verify-screen-pin', ['pin' => '9876'])
            ->assertOk();

        $this->postJson('/api/v1/auth/verify-screen-pin', ['pin' => '0000'])
            ->assertUnprocessable();
    }

    public function test_update_screen_pin_sets_initial_pin_without_current(): void
    {
        $user = User::factory()->create([
            'email' => 'init@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => null,
        ]);
        Passport::actingAs($user);

        $this->putJson('/api/v1/auth/screen-pin', ['pin' => '4242'])
            ->assertOk()
            ->assertJsonPath('data.pinSet', true);

        $user->refresh();

        $this->assertTrue(Hash::check('4242', $user->pin_hash));
    }

    public function test_update_screen_pin_requires_current_when_already_set(): void
    {
        $user = User::factory()->create([
            'email' => 'ch@example.com',
            'password' => Hash::make('pw'),
            'pin_hash' => '1111',
        ]);
        Passport::actingAs($user);

        $this->putJson('/api/v1/auth/screen-pin', ['pin' => '2222'])
            ->assertUnprocessable();

        $this->putJson('/api/v1/auth/screen-pin', [
            'pin' => '2222',
            'currentPin' => '1111',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('2222', $user->pin_hash));
    }
}
