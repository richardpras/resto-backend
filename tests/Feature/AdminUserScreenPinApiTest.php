<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AdminUserScreenPinApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_admin_sets_target_user_screen_pin(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $subject = User::factory()->create([
            'password' => Hash::make('pw'),
            'pin_hash' => null,
        ]);

        $this->putJson('/api/v1/users/'.$subject->id.'/screen-pin', ['pin' => '8844'])
            ->assertOk()
            ->assertJsonPath('data.pinSet', true);

        $subject->refresh();
        $this->assertTrue(Hash::check('8844', $subject->pin_hash));
    }

    public function test_guest_cannot_admin_set_screen_pin(): void
    {
        $subject = User::factory()->create();

        $this->putJson('/api/v1/users/'.$subject->id.'/screen-pin', ['pin' => '1234'])
            ->assertUnauthorized();
    }

    public function test_user_without_assign_roles_cannot_admin_set_screen_pin(): void
    {
        Passport::actingAs(User::factory()->create([
            'password' => Hash::make('pw'),
        ]));

        $subject = User::factory()->create();

        $this->putJson('/api/v1/users/'.$subject->id.'/screen-pin', ['pin' => '1234'])
            ->assertForbidden();
    }

    public function test_create_user_stores_optional_pin(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $resp = $this->postJson('/api/v1/users', [
            'name' => 'Pin User',
            'email' => 'pin-u-'.uniqid('', true).'@example.com',
            'password' => 'secret456',
            'pin' => '7788',
        ])->assertCreated();

        $id = $resp->json('data.id');
        $this->assertIsInt($id);
        /** @var User $u */
        $u = User::query()->findOrFail((int) $id);
        $this->assertTrue(Hash::check('7788', $u->pin_hash));
    }

    public function test_admin_clears_screen_pin(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $subject = User::factory()->create([
            'password' => Hash::make('pw'),
            'pin_hash' => '1212',
        ]);

        $this->deleteJson('/api/v1/users/'.$subject->id.'/screen-pin')
            ->assertOk()
            ->assertJsonPath('data.pinSet', false);

        $subject->refresh();
        $this->assertNull($subject->pin_hash);
    }
}
