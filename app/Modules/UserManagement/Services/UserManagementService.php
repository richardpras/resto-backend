<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;

class UserManagementService
{
    public function listUsers()
    {
        return User::query()->with('roles')->latest('id')->get();
    }

    public function createUser(array $payload): User
    {
        return User::query()->create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => $payload['password'],
        ]);
    }

    public function assignRoles(int $userId, array $roleIds): ?User
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }

        $user->roles()->sync($roleIds);

        return $user->load('roles');
    }

    public function listRoles()
    {
        return Role::query()->with('permissions')->latest('id')->get();
    }

    public function createRole(array $payload): Role
    {
        return Role::query()->create([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
        ]);
    }

    public function assignPermissions(int $roleId, array $permissionIds): ?Role
    {
        $role = Role::query()->find($roleId);
        if ($role === null) {
            return null;
        }

        $role->permissions()->sync($permissionIds);

        return $role->load('permissions');
    }

    public function listPermissions()
    {
        return Permission::query()->latest('id')->get();
    }

    public function createPermission(array $payload): Permission
    {
        return Permission::query()->create([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
        ]);
    }
}
