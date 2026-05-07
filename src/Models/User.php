<?php

declare(strict_types=1);

namespace PII\Models;

use PII\Core\Database;

/**
 * User Model — authentication and CRUD.
 * Passwords are hashed with Argon2id.
 */
class User
{
    public static function findById(int $id): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT id, username, email, display_name, is_active,
                    last_login, created_at, updated_at
             FROM users WHERE id = ?",
            [$id]
        );
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::getInstance()->fetch(
            "SELECT id, username, email, password_hash, display_name,
                    is_active, last_login, created_at, updated_at
             FROM users WHERE username = ?",
            [$username]
        );
    }

    public static function all(array $filters = []): array
    {
        $db     = Database::getInstance();
        $where  = [];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[]  = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(username LIKE ? OR email LIKE ? OR display_name LIKE ?)';
            $term     = '%' . $filters['search'] . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT id, username, email, display_name, is_active,
                       last_login, created_at, updated_at
                FROM users
                {$whereSQL}
                ORDER BY username ASC";
        return $db->fetchAll($sql, $params);
    }

    public static function create(array $data): int
    {
        $db = Database::getInstance();

        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if ($username === '') {
            throw new \InvalidArgumentException('Username is required.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email address is not valid.');
        }
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }

        if ($email !== '') {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ? OR email = ?", [$username, $email]);
        } else {
            $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        }
        if ($existing) {
            throw new \RuntimeException('A user with that username' . ($email ? ' or email' : '') . ' already exists.');
        }

        return (int) $db->insert('users', [
            'username'      => $username,
            'email'         => $email !== '' ? $email : null,
            'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
            'display_name'  => trim($data['display_name'] ?? ''),
            'is_active'     => isset($data['is_active']) ? (int) $data['is_active'] : 1,
        ]);
    }

    public static function updateUser(int $id, array $data): int
    {
        $db = Database::getInstance();
        $updateData = [];

        if (isset($data['username']) && trim($data['username']) !== '') {
            $dup = $db->fetch("SELECT id FROM users WHERE username = ? AND id != ?", [trim($data['username']), $id]);
            if ($dup) {
                throw new \RuntimeException('Username already taken.');
            }
            $updateData['username'] = trim($data['username']);
        }

        if (array_key_exists('email', $data)) {
            $email = trim($data['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Email address is not valid.');
            }
            if ($email !== '') {
                $dup = $db->fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $id]);
                if ($dup) {
                    throw new \RuntimeException('Email already taken.');
                }
            }
            $updateData['email'] = $email !== '' ? $email : null;
        }

        if (isset($data['display_name'])) {
            $updateData['display_name'] = trim($data['display_name']);
        }
        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) $data['is_active'];
        }
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 8) {
                throw new \InvalidArgumentException('Password must be at least 8 characters.');
            }
            $updateData['password_hash'] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($updateData)) {
            return 0;
        }
        return $db->update('users', $updateData, 'id = ?', [$id]);
    }

    public static function delete(int $id): int
    {
        return Database::getInstance()->delete('users', 'id = ?', [$id]);
    }

    public static function authenticate(string $username, string $password): array|false
    {
        $user = self::findByUsername($username);
        if (!$user || !(int) $user['is_active']) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Rehash if algorithm cost has changed
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Database::getInstance()->update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)],
                'id = ?',
                [$user['id']]
            );
        }

        unset($user['password_hash']);

        // Attach group / admin info
        $user['is_admin'] = self::isAdmin((int) $user['id']) ? 1 : 0;
        $user['groups']   = self::getGroups((int) $user['id']);

        return $user;
    }

    public static function updateLastLogin(int $id): void
    {
        Database::getInstance()->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     *  Group / admin helpers
     * ----------------------------------------------------------------*/

    public static function isAdmin(int $userId): bool
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

    public static function getGroups(int $userId): array
    {
        return Database::getInstance()->fetchAll(
            "SELECT pg.id, pg.name, pg.is_admin
             FROM user_group_members ugm
             JOIN permission_groups pg ON pg.id = ugm.group_id
             WHERE ugm.user_id = ?
             ORDER BY pg.name",
            [$userId]
        );
    }

    public static function setGroups(int $userId, array $groupIds): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $db->delete('user_group_members', 'user_id = ?', [$userId]);
            foreach (array_unique($groupIds) as $gid) {
                $gid = (int) $gid;
                if ($gid > 0) {
                    $db->insert('user_group_members', ['user_id' => $userId, 'group_id' => $gid]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
