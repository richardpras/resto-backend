<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UserManagementScopeService
{
    public function isFullAdministrator(?User $actor): bool
    {
        return $actor !== null && $actor->hasPermission('users.manage');
    }

    public function isPrivilegedUser(User $target): bool
    {
        return $target->roles()->where('staff_assignable', false)->exists();
    }

    /**
     * @return Builder<Role>
     */
    public function assignableRolesQuery(): Builder
    {
        return Role::query()->where('staff_assignable', true);
    }

    /**
     * @return Builder<User>
     */
    public function visibleUsersQuery(?User $actor): Builder
    {
        $query = User::query();

        if ($this->isFullAdministrator($actor)) {
            return $query;
        }

        return $query->whereDoesntHave('roles', fn (Builder $roleQuery) => $roleQuery->where('staff_assignable', false));
    }

    /**
     * @param  list<int>  $roleIds
     */
    public function assertCanAssignRoles(?User $actor, User $target, array $roleIds): void
    {
        if ($this->isFullAdministrator($actor)) {
            return;
        }

        if ($this->isPrivilegedUser($target)) {
            throw ValidationException::withMessages([
                'roleIds' => ['You cannot modify roles for this user.'],
            ]);
        }

        $invalidCount = Role::query()
            ->whereIn('id', $roleIds)
            ->where('staff_assignable', false)
            ->count();

        if ($invalidCount > 0) {
            throw ValidationException::withMessages([
                'roleIds' => ['One or more selected roles cannot be assigned.'],
            ]);
        }
    }

    /**
     * @param  Builder<UserManagementAuditLog>  $query
     */
    public function applyScopedAuditFilters(Builder $query, ?User $actor): void
    {
        if ($this->isFullAdministrator($actor)) {
            return;
        }

        $privilegedUserIds = User::query()
            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('staff_assignable', false))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $privilegedRoleIds = Role::query()
            ->where('staff_assignable', false)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $query->where(function (Builder $scoped) use ($privilegedUserIds, $privilegedRoleIds): void {
            $scoped->where(function (Builder $userScoped) use ($privilegedUserIds): void {
                $userScoped->where(function (Builder $entityUser) use ($privilegedUserIds): void {
                    $entityUser->where('entity_type', '!=', UserManagementAuditLog::ENTITY_USER);
                    if ($privilegedUserIds !== []) {
                        $entityUser->orWhere(function (Builder $allowedUserEntity) use ($privilegedUserIds): void {
                            $allowedUserEntity
                                ->where('entity_type', UserManagementAuditLog::ENTITY_USER)
                                ->whereNotIn('entity_id', $privilegedUserIds);
                        });
                    } else {
                        $entityUser->orWhere('entity_type', UserManagementAuditLog::ENTITY_USER);
                    }
                });

                if ($privilegedUserIds !== []) {
                    $userScoped->where(function (Builder $targetUser) use ($privilegedUserIds): void {
                        $targetUser
                            ->whereNull('target_user_id')
                            ->orWhereNotIn('target_user_id', $privilegedUserIds);
                    });
                }
            });

            $scoped->where(function (Builder $roleScoped) use ($privilegedRoleIds): void {
                $roleScoped->where('entity_type', '!=', UserManagementAuditLog::ENTITY_ROLE);
                if ($privilegedRoleIds !== []) {
                    $roleScoped->orWhere(function (Builder $allowedRoleEntity) use ($privilegedRoleIds): void {
                        $allowedRoleEntity
                            ->where('entity_type', UserManagementAuditLog::ENTITY_ROLE)
                            ->whereNotIn('entity_id', $privilegedRoleIds);
                    });
                } else {
                    $roleScoped->orWhere('entity_type', UserManagementAuditLog::ENTITY_ROLE);
                }
            });

            $scoped->where('entity_type', '!=', UserManagementAuditLog::ENTITY_PERMISSION);
        });
    }
}
