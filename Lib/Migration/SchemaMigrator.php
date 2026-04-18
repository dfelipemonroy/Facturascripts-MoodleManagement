<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Migration;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * Idempotent schema migration runner.
 *
 * Invokes a named migration once and records its success in the
 * `moodle_schema_version` table (auto-created on first run). Every
 * migration is run inside a transaction when the backend supports
 * one, so partial states are avoided.
 *
 * Why not raw SQL files:
 *   MySQL < 8.0 does not support `CREATE INDEX IF NOT EXISTS` and
 *   differs from PostgreSQL on several DDL statements. We perform
 *   the conditional checks in PHP by querying information_schema,
 *   which works uniformly across both backends FS supports.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F5.1 · §5.5
 */
final class SchemaMigrator
{
    /** @var DataBase */
    private $db;

    public function __construct(?DataBase $db = null)
    {
        $this->db = $db ?? new DataBase();
    }

    /**
     * Ensure the `moodle_schema_version` table exists so subsequent
     * apply() calls can record outcomes. Safe to call many times.
     *
     * @since 2.0 F5.13
     */
    public function ensureVersionTable(): bool
    {
        if ($this->tableExists('moodle_schema_version')) {
            return true;
        }

        $sql = $this->isPostgres()
            ? 'CREATE TABLE moodle_schema_version ('
                . 'version VARCHAR(40) PRIMARY KEY,'
                . 'applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
                . 'notes VARCHAR(255))'
            : 'CREATE TABLE moodle_schema_version ('
                . 'version VARCHAR(40) NOT NULL PRIMARY KEY,'
                . 'applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,'
                . 'notes VARCHAR(255))'
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

        if (!$this->db->exec($sql)) {
            Tools::log()->error('schema-version-create-failed');
            return false;
        }
        return true;
    }

    /**
     * Run a migration if not already applied.
     *
     * @param string $version e.g. "2.0.0-F5.3-fk-indexes"
     * @param callable $fn Receives the SchemaMigrator instance
     *                     and returns bool (true = success).
     * @param string $notes Optional human description.
     * @return bool True on success (or if already applied).
     */
    public function apply(string $version, callable $fn, string $notes = ''): bool
    {
        if (!$this->ensureVersionTable()) {
            // Fall back to running without record-keeping.
            try {
                return (bool) $fn($this);
            } catch (\Throwable $e) {
                Tools::log()->error('migration-error', [
                'version' => $version,
                'message' => $e->getMessage(),
                ]);
                return false;
            }
        }

        if ($this->versionApplied($version)) {
            return true;
        }

        $this->db->beginTransaction();
        try {
            $ok = (bool) $fn($this);
            if (!$ok) {
                $this->db->rollback();
                Tools::log()->warning('migration-returned-false', ['version' => $version]);
                return false;
            }
            $this->recordVersion($version, $notes);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollback();
            Tools::log()->error('migration-exception', [
                'version' => $version,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Introspection helpers (information_schema portable) ────────

    public function tableExists(string $table): bool
    {
        $sql = 'SELECT 1 FROM information_schema.tables WHERE table_schema = '
            . $this->currentSchemaExpr()
            . ' AND table_name = ' . $this->db->var2str($table);
        $rows = $this->db->select($sql);
        return !empty($rows);
    }

    public function columnExists(string $table, string $column): bool
    {
        $sql = 'SELECT 1 FROM information_schema.columns WHERE table_schema = '
            . $this->currentSchemaExpr()
            . ' AND table_name = ' . $this->db->var2str($table)
            . ' AND column_name = ' . $this->db->var2str($column);
        $rows = $this->db->select($sql);
        return !empty($rows);
    }

    /**
     * Returns the declared character length (bytes) of a VARCHAR
     * column, or null when the column is missing or the backend
     * cannot answer.
     *
     * Used by DB-03 to assert that F5.11 has widened
     * `moodle_instances.token` before F5.12 tries to write ciphertext.
     *
     * @since 2.0 — DB-03 (2026-04-17)
     */
    public function columnCharLength(string $table, string $column): ?int
    {
        if (!$this->columnExists($table, $column)) {
            return null;
        }
        $sql = 'SELECT character_maximum_length AS cml'
            . ' FROM information_schema.columns'
            . ' WHERE table_schema = ' . $this->currentSchemaExpr()
            . ' AND table_name = ' . $this->db->var2str($table)
            . ' AND column_name = ' . $this->db->var2str($column);
        try {
            $rows = $this->db->select($sql);
        } catch (\Throwable $e) {
            return null;
        }
        if (empty($rows) || !isset($rows[0]['cml'])) {
            return null;
        }
        return is_numeric($rows[0]['cml']) ? (int) $rows[0]['cml'] : null;
    }

    public function indexExists(string $table, string $indexName): bool
    {
        if ($this->isPostgres()) {
            $sql = 'SELECT 1 FROM pg_indexes WHERE tablename = '
                . $this->db->var2str($table)
                . ' AND indexname = ' . $this->db->var2str($indexName);
        } else {
            $sql = 'SELECT 1 FROM information_schema.statistics WHERE table_schema = '
                . $this->currentSchemaExpr()
                . ' AND table_name = ' . $this->db->var2str($table)
                . ' AND index_name = ' . $this->db->var2str($indexName);
        }
        $rows = $this->db->select($sql);
        return !empty($rows);
    }

    public function constraintExists(string $table, string $constraintName): bool
    {
        $sql = 'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = '
            . $this->currentSchemaExpr()
            . ' AND table_name = ' . $this->db->var2str($table)
            . ' AND constraint_name = ' . $this->db->var2str($constraintName);
        $rows = $this->db->select($sql);
        return !empty($rows);
    }

    public function versionApplied(string $version): bool
    {
        if (!$this->tableExists('moodle_schema_version')) {
            return false;
        }
        $sql = 'SELECT 1 FROM moodle_schema_version WHERE version = ' . $this->db->var2str($version);
        return !empty($this->db->select($sql));
    }

    // ─── Convenience DDL helpers ────────────────────────────────────

    /**
     * Create an index only if it does not exist.
     */
    public function createIndexIfMissing(string $table, string $indexName, string $columnExpr): bool
    {
        if ($this->indexExists($table, $indexName)) {
            return true;
        }
        $sql = sprintf('CREATE INDEX %s ON %s (%s)', $indexName, $table, $columnExpr);
        return (bool) $this->db->exec($sql);
    }

    /**
     * Add a column only if it does not exist. `$columnDef` must be a
     * full column definition, e.g. "VARCHAR(50) NULL".
     */
    public function addColumnIfMissing(string $table, string $column, string $columnDef): bool
    {
        if ($this->columnExists($table, $column)) {
            return true;
        }
        $sql = sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $columnDef);
        return (bool) $this->db->exec($sql);
    }

    /**
     * Raw DB access for callers that need to execute arbitrary
     * statements after their own conditional checks.
     */
    public function db(): DataBase
    {
        return $this->db;
    }

    public function isPostgres(): bool
    {
        return strtolower((string) Tools::config('db_type')) === 'postgresql';
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function recordVersion(string $version, string $notes): void
    {
        $sql = 'INSERT INTO moodle_schema_version (version, notes) VALUES ('
            . $this->db->var2str($version) . ', ' . $this->db->var2str($notes) . ')';
        $this->db->exec($sql);
    }

    /**
     * SQL expression that resolves to the current schema name at
     * query time. Exposed for migration files that need to build
     * `information_schema` lookups of their own (DB-12).
     *
     * @since 2.0 — visibility bumped in F17 (2026-04-17)
     */
    public function currentSchemaExpr(): string
    {
        if ($this->isPostgres()) {
            return 'current_schema()';
        }
        return 'DATABASE()';
    }
}
