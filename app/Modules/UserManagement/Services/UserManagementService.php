<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use App\Models\User;

class UserManagementService
{
    public function __construct(
        private readonly UserManagementAuditService $audit,
    ) {}

    public function listUsers()
    {
        return User::query()->with('roles')->latest('id')->get();
    }

    public function createUser(?User $actor, array $payload): User
    {
        $data = [
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => $payload['password'],
        ];
        $pinProvided = ! empty($payload['pin']);
        if ($pinProvided) {
            $data['pin_hash'] = $payload['pin'];
        }

        $user = User::query()->create($data);
        $user->load('roles');

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_USER_CREATED,
            UserManagementAuditLog::ENTITY_USER,
            (int) $user->id,
            (int) $user->id,
            null,
            $this->audit->snapshotUser($user),
            $pinProvided ? ['pinConfigured' => true] : null,
        );

        if ($pinProvided) {
            $this->audit->record(
                $actor,
                UserManagementAuditLog::ACTION_USER_PIN_SET,
                UserManagementAuditLog::ENTITY_USER,
                (int) $user->id,
                (int) $user->id,
                ['pinSet' => false],
                ['pinSet' => true],
            );
        }

        return $user;
    }

    /** @param non-empty-string $pin plaintext 4-digit PIN (hashed on save) */
    public function adminSetUserScreenPin(?User $actor, int $userId, string $pin): ?User
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }

        $beforePinSet = $user->pin_hash !== null;
        $user->pin_hash = $pin;
        $user->save();

        $user = $user->load('roles');

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_USER_PIN_SET,
            UserManagementAuditLog::ENTITY_USER,
            (int) $user->id,
            (int) $user->id,
            ['pinSet' => $beforePinSet],
            ['pinSet' => true],
        );

        return $user;
    }

    public function adminClearUserScreenPin(?User $actor, int $userId): ?User
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }

        $beforePinSet = $user->pin_hash !== null;
        $user->pin_hash = null;
        $user->save();

        $user = $user->load('roles');

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_USER_PIN_CLEARED,
            UserManagementAuditLog::ENTITY_USER,
            (int) $user->id,
            (int) $user->id,
            ['pinSet' => $beforePinSet],
            ['pinSet' => false],
        );

        return $user;
    }

    public function assignRoles(?User $actor, int $userId, array $roleIds): ?User
    {
        $user = User::query()->with('roles')->find($userId);
        if ($user === null) {
            return null;
        }

        $before = $this->audit->snapshotUser($user);
        $beforeRoleIds = $before['roleIds'];

        $user->roles()->sync($roleIds);
        $user = $user->load('roles');
        $after = $this->audit->snapshotUser($user);

        $diff = $this->audit->diffIds($beforeRoleIds, $after['roleIds']);

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_ROLE_PERMISSION_CHANGED,
            UserManagementAuditLog::ENTITY_USER,
            (int) $user->id,
            (int) $user->id,
            $before,
            $after,
            [
                'grantedRoleIds' => $diff['granted'],
                'revokedRoleIds' => $diff['revoked'],
            ],
        );

        return $user;
    }

    public function listRoles()
    {
        return Role::query()->with('permissions')->latest('id')->get();
    }

    public function createRole(?User $actor, array $payload): Role
    {
        $role = Role::query()->create([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
        ]);

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_ROLE_CREATED,
            UserManagementAuditLog::ENTITY_ROLE,
            (int) $role->id,
            null,
            null,
            $this->audit->snapshotRole($role),
        );

        return $role;
    }

    public function assignPermissions(?User $actor, int $roleId, array $permissionIds): ?Role
    {
        $role = Role::query()->with('permissions')->find($roleId);
        if ($role === null) {
            return null;
        }

        $before = $this->audit->snapshotRole($role);
        $beforePermissionIds = $before['permissionIds'];

        $role->permissions()->sync($permissionIds);
        $role = $role->load('permissions');
        $after = $this->audit->snapshotRole($role);

        $diff = $this->audit->diffIds($beforePermissionIds, $after['permissionIds']);

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_ROLE_PERMISSION_CHANGED,
            UserManagementAuditLog::ENTITY_ROLE,
            (int) $role->id,
            null,
            $before,
            $after,
            [
                'grantedPermissionIds' => $diff['granted'],
                'revokedPermissionIds' => $diff['revoked'],
            ],
        );

        return $role;
    }

    public function listPermissions()
    {
        return Permission::query()->latest('id')->get();
    }

    public function createPermission(?User $actor, array $payload): Permission
    {
        $permission = Permission::query()->create([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
        ]);

        $this->audit->record(
            $actor,
            UserManagementAuditLog::ACTION_PERMISSION_CREATED,
            UserManagementAuditLog::ENTITY_PERMISSION,
            (int) $permission->id,
            null,
            null,
            $this->audit->snapshotPermission($permission),
        );

        return $permission;
    }
}
