<?php

namespace Origen\Storage\Database;

class TokenRepository
{
    public function __construct(private Connection $connection) {}

    public function isUsed(string $jti): bool
    {
        $stmt = $this->connection->query('SELECT 1 FROM used_tokens WHERE jti = ?', [$jti]);
        return (bool) $stmt->fetch();
    }

    public function markUsed(string $jti, int $siteId, string $expiresAt): void
    {
        $this->connection->execute(
            'INSERT OR IGNORE INTO used_tokens (jti, site_id, expires_at) VALUES (?, ?, ?)',
            [$jti, $siteId, $expiresAt]
        );
    }

    public function cleanupExpired(): int
    {
        return $this->connection->execute(
            "DELETE FROM used_tokens WHERE expires_at < datetime('now')"
        );
    }
}
