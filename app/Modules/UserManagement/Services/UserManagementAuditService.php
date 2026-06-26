<?php

namespace App\Modules\UserManagement\Services;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\UserManagement\Domain\UserManagementAuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserManagementAuditService
{
    /** @var list<string> */
    private const FORBIDDEN_PAYLOAD_KEYS = ['password', 'pin', 'pin_hash', 'token', 'accessToken'];

    public function record(
        ?User $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?int $targetUserId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): UserManagementAuditLog {
        return UserManagementAuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'target_user_id' => $targetUserId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $this->sanitizePayload($before),
            'after_json' => $this->sanitizePayload($after),
            'metadata' => $this->sanitizePayload($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['limit'] ?? 25)));

        $query = UserManagementAuditLog::query()
            ->with(['actor', 'targetUser'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);
        app(UserManagementScopeService::class)->applyScopedAuditFilters($query, $actor);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotUser(User $user): array
    {
        $user->loadMissing('roles');

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roleIds' => $user->roles->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'roleNames' => $user->roles->pluck('name')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotRole(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => (int) $role->id,
            'name' => $role->name,
            'permissionIds' => $role->permissions->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'permissionCodes' => $role->permissions->pluck('code')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotPermission(Permission $permission): array
    {
        return [
            'id' => (int) $permission->id,
            'code' => $permission->code,
            'name' => $permission->name,
        ];
    }

    /**
     * @param  list<int>  $beforeIds
     * @param  list<int>  $afterIds
     * @return array{granted: list<int>, revoked: list<int>}
     */
    public function diffIds(array $beforeIds, array $afterIds): array
    {
        $before = array_values(array_unique(array_map('intval', $beforeIds)));
        $after = array_values(array_unique(array_map('intval', $afterIds)));

        return [
            'granted' => array_values(array_diff($after, $before)),
            'revoked' => array_values(array_diff($before, $after)),
        ];
    }

    /**
     * @param  Builder<UserManagementAuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['action']) && is_string($filters['action']) && $filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['entityType']) && is_string($filters['entityType']) && $filters['entityType'] !== '') {
            $query->where('entity_type', $filters['entityType']);
        }

        if (isset($filters['entityId']) && (int) $filters['entityId'] > 0) {
            $query->where('entity_id', (int) $filters['entityId']);
        }

        if (isset($filters['targetUserId']) && (int) $filters['targetUserId'] > 0) {
            $query->where('target_user_id', (int) $filters['targetUserId']);
        }

        if (isset($filters['actorUserId']) && (int) $filters['actorUserId'] > 0) {
            $query->where('actor_user_id', (int) $filters['actorUserId']);
        }

        if (isset($filters['fromDate']) && is_string($filters['fromDate']) && $filters['fromDate'] !== '') {
            $query->whereDate('created_at', '>=', $filters['fromDate']);
        }

        if (isset($filters['toDate']) && is_string($filters['toDate']) && $filters['toDate'] !== '') {
            $query->whereDate('created_at', '<=', $filters['toDate']);
        }

        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function (Builder $q) use ($term, $filters): void {
                $q->where('action', 'like', $term)
                    ->orWhere('entity_type', 'like', $term)
                    ->orWhereHas('actor', fn (Builder $actor) => $actor
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term))
                    ->orWhereHas('targetUser', fn (Builder $target) => $target
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));

                if (is_numeric($filters['search'])) {
                    $q->orWhere('entity_id', (int) $filters['search'])
                        ->orWhere('target_user_id', (int) $filters['search'])
                        ->orWhere('actor_user_id', (int) $filters['search']);
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $sanitized = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
