<?php

declare(strict_types=1);

namespace PII\Services;

use PII\Core\Database;

/**
 * PermissionService — Group-based permission checks.
 *
 * For each (user, page_key) pair the user gets the highest level
 * granted by any of their groups: 'none' < 'read' < 'full'.
 *
 * Admin group members bypass all checks.
 */
class PermissionService
{
    /** Effective access level for a user on a given page. */
    public static function level(int $userId, string $pageKey): string
    {
        if (self::isAdminUser($userId)) {
            return 'full';
        }

        $row = Database::getInstance()->fetch(
            "SELECT MAX(
                CASE access_level
                    WHEN 'full' THEN 2
                    WHEN 'read' THEN 1
                    ELSE 0
                END
             ) AS lvl
             FROM group_permissions gp
             JOIN user_group_members ugm ON ugm.group_id = gp.group_id
             WHERE ugm.user_id = ? AND gp.page_key = ?",
            [$userId, $pageKey]
        );

        $lvl = (int) ($row['lvl'] ?? 0);
        return match ($lvl) {
            2 => 'full',
            1 => 'read',
            default => 'none',
        };
    }

    public static function canRead(int $userId, string $pageKey): bool
    {
        return self::level($userId, $pageKey) !== 'none';
    }

    public static function canEdit(int $userId, string $pageKey): bool
    {
        return self::level($userId, $pageKey) === 'full';
    }

    public static function isAdminUser(int $userId): bool
    {
        $row = Database::getInstance()->fetch(
            "SELECT 1
             FROM user_group_members ugm
             JOIN permission_groups pg ON pg.id = ugm.group_id
             WHERE ugm.user_id = ? AND pg.is_admin = 1
             LIMIT 1",
            [$userId]
        );
        return (bool) $row;
    }
}
