<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use App\Models\User;
use App\Modules\System\Services\AuditCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class UserManagementAuditLogTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_assign_roles_writes_audit_with_before_and_after_role_ids(): void
    {
        $actor = $this->actingAsUserManagementApiAdministrator();

        $target = User::factory()->create(['email' => 'audit-target@test.local']);
        $roleA = Role::query()->create(['name' => '__audit_role_a__', 'description' => null]);
        $roleB = Role::query()->create(['name' => '__audit_role_b__', 'description' => null]);
        $target->roles()->sync([$roleA->id]);

        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'roleIds' => [$roleA->id, $roleB->id],
        ])->assertOk();

        $log = UserManagementAuditLog::query()
            ->where('action', UserManagementAuditLog::ACTION_ROLE_PERMISSION_CHANGED)
            ->where('entity_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame((int) $actor->id, (int) $log->actor_user_id);
        $this->assertSame([$roleA->id], $log->before_json['roleIds'] ?? null);
        $this->assertEqualsCanonicalizing([$roleA->id, $roleB->id], $log->after_json['roleIds'] ?? []);
        $this->assertSame([$roleB->id], $log->metadata['grantedRoleIds'] ?? null);
    }

    public function test_admin_set_screen_pin_audit_does_not_store_pin_values(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $target = User::factory()->create(['email' => 'pin-target@test.local']);

        $this->putJson("/api/v1/users/{$target->id}/screen-pin", [
            'pin' => '1234',
        ])->assertOk();

        $log = UserManagementAuditLog::query()
            ->where('action', UserManagementAuditLog::ACTION_USER_PIN_SET)
            ->where('entity_id', $target->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);

        $encoded = json_encode([
            $log->before_json,
            $log->after_json,
            $log->metadata,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('1234', $encoded);
        $this->assertStringNotContainsStringIgnoringCase('password', $encoded);
        $this->assertStringNotContainsString('pin_hash', $encoded);
        $this->assertTrue($log->after_json['pinSet'] ?? false);
    }

    public function test_audit_logs_list_endpoint_returns_paginated_rows(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $target = User::factory()->create(['email' => 'list-target@test.local']);
        $role = Role::query()->first();

        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'roleIds' => [$role->id],
        ])->assertOk();

        $response = $this->getJson('/api/v1/user-management/audit-logs?limit=10')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'action',
                        'entityType',
                        'entityId',
                        'actorUserId',
                        'createdAt',
                    ],
                ],
                'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
            ]);

        $this->assertGreaterThanOrEqual(1, $response->json('meta.total'));
        $this->assertSame('role_permission_changed', $response->json('data.0.action'));
    }

    public function test_user_without_users_view_cannot_list_audit_logs(): void
    {
        $user = User::factory()->create();
        Passport::actingAs($user);

        $this->getJson('/api/v1/user-management/audit-logs')->assertForbidden();
    }

    public function test_audit_center_timeline_includes_user_management_events(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $target = User::factory()->create(['email' => 'center-target@test.local']);
        $role = Role::query()->first();

        $this->postJson("/api/v1/users/{$target->id}/roles", [
            'roleIds' => [$role->id],
        ])->assertOk();

        $result = app(AuditCenterService::class)->listTimeline(['limit' => 50]);

        $hasUserMgmt = false;
        foreach ($result['data'] as $row) {
            if ($row->module === 'user_management') {
                $hasUserMgmt = true;
                break;
            }
        }

        $this->assertTrue($hasUserMgmt, 'Expected user_management module in audit center timeline');
    }
}
