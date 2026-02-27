<?php

namespace Origen\Storage\Database;

class Migrator
{
    public function __construct(private Connection $connection) {}

    public function run(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                domain TEXT NOT NULL UNIQUE,
                api_key TEXT NOT NULL UNIQUE,
                settings TEXT DEFAULT '{}',
                active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )",

            "CREATE TABLE IF NOT EXISTS content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                type TEXT NOT NULL,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT DEFAULT '',
                status TEXT DEFAULT 'draft',
                file_path TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now')),
                UNIQUE(site_id, slug)
            )",

            "CREATE TABLE IF NOT EXISTS content_field_values (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
                field_name TEXT NOT NULL,
                field_value TEXT,
                UNIQUE(content_id, field_name)
            )",

            "CREATE TABLE IF NOT EXISTS field_schemas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                content_type TEXT NOT NULL,
                field_name TEXT NOT NULL,
                field_type TEXT NOT NULL,
                constraints TEXT DEFAULT '{}',
                ui_hints TEXT DEFAULT '{}',
                sort_order INTEGER DEFAULT 0,
                UNIQUE(site_id, content_type, field_name)
            )",

            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                force_password_reset INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )",

            "CREATE TABLE IF NOT EXISTS memberships (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role TEXT DEFAULT 'viewer',
                UNIQUE(site_id, user_id)
            )",

            "CREATE TABLE IF NOT EXISTS used_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                jti TEXT NOT NULL UNIQUE,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                expires_at TEXT NOT NULL
            )",

            "CREATE TABLE IF NOT EXISTS content_type_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                content_type TEXT NOT NULL,
                storage_mode TEXT DEFAULT 'content',
                retention_days INTEGER,
                retention_field TEXT DEFAULT 'created_at',
                UNIQUE(site_id, content_type)
            )",
        ];

        foreach ($statements as $sql) {
            $this->connection->pdo()->exec($sql);
        }

        // Run upgrade migrations for existing databases
        $this->upgrade();
    }

    /**
     * Run upgrade migrations for schema changes on existing databases.
     */
    private function upgrade(): void
    {
        // Add force_password_reset column if missing (v1.1+)
        $this->addColumnIfMissing('users', 'force_password_reset', 'INTEGER DEFAULT 0');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        $stmt = $this->connection->pdo()->query("PRAGMA table_info({$table})");
        $columns = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'name');

        if (!in_array($column, $columns)) {
            $this->connection->pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }
}
