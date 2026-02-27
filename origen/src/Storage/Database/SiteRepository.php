<?php

namespace Origen\Storage\Database;

class SiteRepository
{
    public function __construct(private Connection $connection) {}

    public function findByApiKey(string $apiKey): ?array
    {
        $stmt = $this->connection->query(
            'SELECT * FROM sites WHERE api_key = ? AND active = 1',
            [$apiKey]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->connection->query('SELECT * FROM sites WHERE slug = ?', [$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->connection->query('SELECT * FROM sites WHERE id = ?', [$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Upsert a site by api_key.
     */
    public function upsert(array $data): array
    {
        $existing = $this->findByApiKey($data['api_key']);

        if ($existing) {
            $this->connection->execute(
                'UPDATE sites SET name = ?, domain = ?, slug = ?, settings = ?, active = ?, updated_at = datetime(\'now\') WHERE id = ?',
                [
                    $data['name'],
                    $data['domain'],
                    $data['slug'],
                    json_encode($data['settings'] ?? []),
                    $data['active'] ?? 1,
                    $existing['id'],
                ]
            );
            return $this->findById($existing['id']);
        }

        $this->connection->execute(
            'INSERT INTO sites (slug, name, domain, api_key, settings, active) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['slug'],
                $data['name'],
                $data['domain'],
                $data['api_key'],
                json_encode($data['settings'] ?? []),
                $data['active'] ?? 1,
            ]
        );

        return $this->findById($this->connection->lastInsertId());
    }

    public function all(): array
    {
        return $this->connection->query('SELECT * FROM sites')->fetchAll();
    }
}
