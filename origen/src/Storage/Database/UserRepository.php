<?php

namespace Origen\Storage\Database;

class UserRepository
{
    public function __construct(private Connection $connection) {}

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->connection->query('SELECT * FROM users WHERE email = ?', [$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->query('SELECT * FROM users WHERE id = ?', [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name, string $email, string $passwordHash, bool $forceReset = false): array
    {
        $this->connection->execute(
            'INSERT INTO users (name, email, password_hash, force_password_reset) VALUES (?, ?, ?, ?)',
            [$name, $email, $passwordHash, $forceReset ? 1 : 0]
        );
        return $this->findById($this->connection->lastInsertId());
    }

    public function updatePassword(int $userId, string $passwordHash, bool $clearForceReset = true): void
    {
        $this->connection->execute(
            'UPDATE users SET password_hash = ?, force_password_reset = ?, updated_at = datetime(\'now\') WHERE id = ?',
            [$passwordHash, $clearForceReset ? 0 : 1, $userId]
        );
    }

    public function requiresPasswordReset(int $userId): bool
    {
        $user = $this->findById($userId);
        return $user && ($user['force_password_reset'] ?? 0) == 1;
    }

    public function findMembership(int $userId, int $siteId): ?array
    {
        $stmt = $this->connection->query(
            'SELECT * FROM memberships WHERE user_id = ? AND site_id = ?',
            [$userId, $siteId]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createMembership(int $userId, int $siteId, string $role = 'viewer'): array
    {
        $this->connection->execute(
            'INSERT INTO memberships (user_id, site_id, role) VALUES (?, ?, ?)',
            [$userId, $siteId, $role]
        );
        $stmt = $this->connection->query(
            'SELECT * FROM memberships WHERE user_id = ? AND site_id = ?',
            [$userId, $siteId]
        );
        return $stmt->fetch();
    }

    /**
     * Ensure every super_admin user has a membership on every active site.
     * Runs on each boot so new sites automatically get super_admin access.
     */
    public function ensureSuperAdminMemberships(Connection $connection): void
    {
        $connection->execute(
            'INSERT OR IGNORE INTO memberships (user_id, site_id, role)
             SELECT DISTINCT m.user_id, s.id, \'super_admin\'
             FROM memberships m
             CROSS JOIN sites s
             WHERE m.role = \'super_admin\' AND s.active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM memberships m2
                   WHERE m2.user_id = m.user_id AND m2.site_id = s.id
               )'
        );
    }
}
