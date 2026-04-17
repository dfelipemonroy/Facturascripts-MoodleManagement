<?php
/**
 * MoodleManagement — v2.0 schema migrations.
 *
 * Returns an associative array where each key is a version tag (also
 * stored in `moodle_schema_version.version`) and each value is a
 * closure that receives a {@see SchemaMigrator} instance and returns
 * bool true on success.
 *
 * All migrations are idempotent: they check the current schema via
 * information_schema before issuing DDL. Running the whole array
 * twice is a no-op.
 *
 * DOWN:
 *   See Update/v2_0_down.sql for a documented rollback path. The
 *   DOWN script is NOT executed automatically by Init::update(); it
 *   is for operator use via a DB client.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F5.1 · §5.5
 */

declare(strict_types=1);

use FacturaScripts\Plugins\MoodleManagement\Lib\Migration\SchemaMigrator;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\TokenCipher;

return [

    // ──────────────────────────────────────────────────────────────
    //  F5.3 — FK indexes (CRITICAL)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.3-fk-indexes' => static function (SchemaMigrator $m): bool {
        $indexes = [
            ['moodle_user_map',    'idx_mm_um_contacto',    'idcontacto'],
            ['moodle_user_map',    'idx_mm_um_instance',    'idinstance'],
            ['moodle_enrolments',  'idx_mm_enrol_contacto', 'idcontacto'],
            ['moodle_enrolments',  'idx_mm_enrol_cm',       'idcourse_map'],
            ['moodle_enrolments',  'idx_mm_enrol_factura',  'idfactura'],
            ['moodle_course_map',  'idx_mm_cm_instance',    'idinstance'],
            ['moodle_course_map',  'idx_mm_cm_producto',    'idproducto'],
            ['moodle_certificates','idx_mm_cert_usermap',   'idcontacto'],
        ];
        foreach ($indexes as [$table, $idx, $col]) {
            if (!$m->tableExists($table)) {
                continue;
            }
            if (!$m->columnExists($table, $col)) {
                continue;
            }
            $m->createIndexIfMissing($table, $idx, $col);
        }
        return true;
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.5 — preserve invoice code on cascade (CRITICAL)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.5-idfactura-archived' => static function (SchemaMigrator $m): bool {
        // New-schema installs already have ON DELETE SET NULL on
        // idfactura (moodle_enrolments.xml). We only add the archival
        // column used to keep the invoice code after deletion.
        if (!$m->tableExists('moodle_enrolments')) {
            return true;
        }
        return $m->addColumnIfMissing('moodle_enrolments', 'idfactura_archived', 'VARCHAR(50) NULL');
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.8 — UNIQUE on moodle_instances.name (ALTO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.8-instance-name-unique' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_instances')) {
            return true;
        }
        if ($m->constraintExists('moodle_instances', 'uniq_mm_instance_name')) {
            return true;
        }
        // MySQL / MariaDB compatible syntax used by FS.
        return (bool) $m->db()->exec(
            'ALTER TABLE moodle_instances ADD CONSTRAINT uniq_mm_instance_name UNIQUE (name)'
        );
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.11 — widen moodle_instances.token for encrypted payload (ALTO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.11-token-varchar-500' => static function (SchemaMigrator $m): bool {
        if (!$m->columnExists('moodle_instances', 'token')) {
            return true;
        }
        $sql = $m->isPostgres()
            ? 'ALTER TABLE moodle_instances ALTER COLUMN token TYPE VARCHAR(500)'
            : 'ALTER TABLE moodle_instances MODIFY token VARCHAR(500) NULL';
        return (bool) $m->db()->exec($sql);
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.12 — re-encrypt tokens at rest (CRITICAL §4.6)
    //  This is a DATA migration, not DDL. Runs after F5.11 widens
    //  the column so the base64 ciphertext fits.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.12-token-cipher' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_instances') || !$m->columnExists('moodle_instances', 'token')) {
            return true;
        }
        return TokenCipher::encryptExistingRows($m->db());
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.14 — status CHECK constraint (ALTO)
    //  Using CHECK (not MySQL ENUM) because FS core auto-generates
    //  columns from XML and we cannot express ENUM there portably.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.14-enrolment-status-check' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_enrolments')) {
            return true;
        }
        $name = 'chk_mm_enrolment_status';
        if ($m->constraintExists('moodle_enrolments', $name)) {
            return true;
        }
        $allowed = "'pending','enrolled','suspended','unenrolled','expired','cancelled'";
        $sql = "ALTER TABLE moodle_enrolments ADD CONSTRAINT {$name} CHECK (status IN ({$allowed}) OR status IS NULL)";
        // MySQL < 8.0.16 parses CHECK but ignores it. That's
        // acceptable — EnrolmentStatus enum is the application-side
        // source of truth anyway.
        return (bool) $m->db()->exec($sql);
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.15 — audit trail columns (ALTO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.15-created-updated-by' => static function (SchemaMigrator $m): bool {
        $targets = [
            'moodle_instances',
            'moodle_user_map',
            'moodle_course_map',
            'moodle_enrolments',
            'moodle_certificates',
            'moodle_cohorts',
        ];
        foreach ($targets as $table) {
            if (!$m->tableExists($table)) {
                continue;
            }
            $m->addColumnIfMissing($table, 'created_by', 'VARCHAR(50) NULL');
            $m->addColumnIfMissing($table, 'updated_by', 'VARCHAR(50) NULL');
        }
        return true;
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.18 — UNIQUE on certificate code (MEDIO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.18-cert-unique-hash' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_certificates')) {
            return true;
        }
        if (!$m->columnExists('moodle_certificates', 'unique_hash')) {
            return true;
        }
        if ($m->constraintExists('moodle_certificates', 'uniq_mm_cert_hash')) {
            return true;
        }
        return (bool) $m->db()->exec(
            'ALTER TABLE moodle_certificates ADD CONSTRAINT uniq_mm_cert_hash UNIQUE (unique_hash)'
        );
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.20 — soft-delete column (MEDIO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.20-soft-delete' => static function (SchemaMigrator $m): bool {
        $targets = ['moodle_user_map', 'moodle_enrolments', 'moodle_cohorts'];
        foreach ($targets as $table) {
            if ($m->tableExists($table)) {
                $m->addColumnIfMissing($table, 'deleted_at', 'TIMESTAMP NULL');
            }
        }
        return true;
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.10 — collation unification (ALTO)
    //  Normalise every plugin table to utf8mb4 / utf8mb4_unicode_520_ci
    //  on MySQL. PostgreSQL uses client_encoding + LC_COLLATE at DB
    //  level, so we skip there.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.10-collation-utf8mb4' => static function (SchemaMigrator $m): bool {
        if ($m->isPostgres()) {
            return true; // not applicable
        }
        $tables = [
            'moodle_instances', 'moodle_user_map', 'moodle_course_map',
            'moodle_enrolments', 'moodle_cohorts', 'moodle_role_map',
            'moodle_course_categories', 'moodle_certificates',
            'moodle_certificate_templates', 'moodle_audit_log',
            'moodle_schema_version',
        ];
        foreach ($tables as $table) {
            if (!$m->tableExists($table)) {
                continue;
            }
            $m->db()->exec(
                'ALTER TABLE ' . $table
                . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
            );
        }
        return true;
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.7 — FK codgrupo -> gruposclientes (ALTO)
    //  moodle_cohorts.codgrupo is the only FS foreign key not
    //  declared in the base table XML. Use ON DELETE SET NULL so
    //  deleting a FS group doesn't cascade-wipe the cohort history.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.7-cohorts-codgrupo-fk' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_cohorts') || !$m->tableExists('gruposclientes')) {
            return true;
        }
        $fk = 'ca_mm_cohorts_codgrupo';
        if ($m->constraintExists('moodle_cohorts', $fk)) {
            return true;
        }
        $sql = 'ALTER TABLE moodle_cohorts ADD CONSTRAINT ' . $fk
            . ' FOREIGN KEY (codgrupo) REFERENCES gruposclientes (codgrupo)'
            . ' ON DELETE SET NULL ON UPDATE CASCADE';
        return (bool) $m->db()->exec($sql);
    },

    // ──────────────────────────────────────────────────────────────
    //  F5.22 — created_at / updated_at on role_map (BAJO)
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F5.22-role-map-timestamps' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_role_map')) {
            return true;
        }
        $m->addColumnIfMissing('moodle_role_map', 'created_at', 'TIMESTAMP NULL');
        $m->addColumnIfMissing('moodle_role_map', 'updated_at', 'TIMESTAMP NULL');
        return true;
    },

    // ──────────────────────────────────────────────────────────────
    //  F6.1 — explicit badge re-sync flag (CRITICAL §1.1)
    //  Cutting the BadgeSyncWorker cascade: the worker now listens
    //  to Model.MoodleUserMap.Insert only. For targeted re-syncs
    //  after a UI action, callers set this flag and enqueue the
    //  worker manually.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F6.1-badge-sync-needed' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_user_map')) {
            return true;
        }
        return $m->addColumnIfMissing('moodle_user_map', 'badge_sync_needed', 'TINYINT(1) NOT NULL DEFAULT 0');
    },

    // ──────────────────────────────────────────────────────────────
    //  F7.4 scaffold — contact sync last_modified column (ALTO §2.12)
    //  Fase 7 F7.4 consumes this column; adding it here keeps the
    //  DB migration surface in one place.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F7.4-contacto-mm-last-modified' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('contactos')) {
            return true;
        }
        return $m->addColumnIfMissing('contactos', 'mm_last_modified', 'TIMESTAMP NULL');
    },

    // ──────────────────────────────────────────────────────────────
    //  F10.1 — webhook shared secret per instance (MEDIO §6.14)
    //  Column stores the TokenCipher-wrapped secret used as HMAC key
    //  for inbound /ApiMoodleWebhook requests. NULL ⇒ webhooks
    //  disabled for the instance.
    // ──────────────────────────────────────────────────────────────
    '2.0.0-F10.1-webhook-secret' => static function (SchemaMigrator $m): bool {
        if (!$m->tableExists('moodle_instances')) {
            return true;
        }
        return $m->addColumnIfMissing('moodle_instances', 'webhook_secret', 'VARCHAR(500) NULL');
    },
];
