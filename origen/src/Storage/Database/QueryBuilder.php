<?php

namespace Origen\Storage\Database;

class QueryBuilder
{
    private Connection $connection;
    private int $siteId;
    private array $conditions = [];
    private array $params = [];
    private ?string $orderColumn = 'created_at';
    private string $orderDirection = 'desc';
    private ?int $limitCount = null;

    public function __construct(Connection $connection, int $siteId)
    {
        $this->connection = $connection;
        $this->siteId = $siteId;
    }

    public function type(string $type): static
    {
        $this->conditions[] = 'type = ?';
        $this->params[] = $type;
        return $this;
    }

    public function slug(string $slug): static
    {
        $this->conditions[] = 'slug = ?';
        $this->params[] = $slug;
        return $this;
    }

    public function recordId(int|string $id): static
    {
        $this->conditions[] = 'id = ?';
        $this->params[] = (int) $id;
        return $this;
    }

    public function status(string $status): static
    {
        $this->conditions[] = 'status = ?';
        $this->params[] = $status;
        return $this;
    }

    public function where(string $column, mixed $value): static
    {
        $this->conditions[] = "{$column} = ?";
        $this->params[] = $value;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'desc'): static
    {
        $this->orderColumn = $column;
        $this->orderDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        return $this;
    }

    public function limit(int $count): static
    {
        $this->limitCount = $count;
        return $this;
    }

    public function get(): array
    {
        $sql = 'SELECT * FROM content WHERE site_id = ?';
        $params = [$this->siteId];

        foreach ($this->conditions as $condition) {
            $sql .= ' AND ' . $condition;
        }
        $params = array_merge($params, $this->params);

        if ($this->orderColumn) {
            $sql .= " ORDER BY {$this->orderColumn} {$this->orderDirection}";
        }

        if ($this->limitCount !== null) {
            $sql .= " LIMIT {$this->limitCount}";
        }

        return $this->connection->query($sql, $params)->fetchAll();
    }

    public function first(): ?array
    {
        $this->limitCount = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }
}
