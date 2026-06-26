<?php

namespace App\Modules\UserManagement\Support;

/**
 * Higher rank = more authority. An actor may only modify users/roles with strictly lower rank.
 */
final class RoleHierarchy
{
    public const RANK_PLATFORM_ADMIN = 100;

    public const RANK_OWNER = 90;

    public const RANK_AUDITOR = 85;

    public const RANK_MANAGER = 50;

    public const RANK_STAFF = 10;

    /**
     * @return array{staff_assignable: bool, hierarchy_rank: int}
     */
    public static function defaultsForRoleName(string $name): array
    {
        $lower = strtolower($name);

        if (str_contains($lower, 'admin')) {
            return ['staff_assignable' => false, 'hierarchy_rank' => self::RANK_PLATFORM_ADMIN];
        }

        if (str_contains($lower, 'owner')) {
            return ['staff_assignable' => false, 'hierarchy_rank' => self::RANK_OWNER];
        }

        if (str_contains($lower, 'auditor')) {
            return ['staff_assignable' => false, 'hierarchy_rank' => self::RANK_AUDITOR];
        }

        if (str_contains($lower, 'manager')) {
            return ['staff_assignable' => true, 'hierarchy_rank' => self::RANK_MANAGER];
        }

        return ['staff_assignable' => true, 'hierarchy_rank' => self::RANK_STAFF];
    }
}
