--
-- MoodleManagement v2.0 migration — DOWN procedure (manual).
-- Not executed automatically. Use in emergency to roll back v2.0 to v1.x.
--
-- Per V2.0-ACTION-PLAN §6 (Policy de rollback).
-- @since 2.0 F5.1
--
-- Prerequisites:
--   1. A pre-upgrade database dump (see UPGRADE.md §1.1).
--   2. Restore the plugin folder to its v1.1 contents.
--   3. Execute the statements below in order.
--
-- WARNING:
--   - F5.12 token re-encryption is lossy: once rows are ciphered,
--     decrypting requires the derivation key to be present. If you
--     lost the key, restore tokens from the sealed envelope noted
--     in UPGRADE.md §1.2 Pre-flight checklist (step 2).
--   - F5.5 idfactura_archived captures invoice codes for deleted
--     invoices. Dropping the column loses that history.
--

-- ── F5.22 role_map timestamps ─────────────────────────────────────
ALTER TABLE moodle_role_map DROP COLUMN IF EXISTS updated_at;
ALTER TABLE moodle_role_map DROP COLUMN IF EXISTS created_at;

-- ── F5.20 soft-delete ────────────────────────────────────────────
ALTER TABLE moodle_cohorts     DROP COLUMN IF EXISTS deleted_at;
ALTER TABLE moodle_enrolments  DROP COLUMN IF EXISTS deleted_at;
ALTER TABLE moodle_user_map    DROP COLUMN IF EXISTS deleted_at;

-- ── F5.18 unique cert hash ───────────────────────────────────────
ALTER TABLE moodle_certificates DROP CONSTRAINT IF EXISTS uniq_mm_cert_hash;

-- ── F5.15 audit columns ──────────────────────────────────────────
ALTER TABLE moodle_cohorts      DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_cohorts      DROP COLUMN IF EXISTS created_by;
ALTER TABLE moodle_certificates DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_certificates DROP COLUMN IF EXISTS created_by;
ALTER TABLE moodle_enrolments   DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_enrolments   DROP COLUMN IF EXISTS created_by;
ALTER TABLE moodle_course_map   DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_course_map   DROP COLUMN IF EXISTS created_by;
ALTER TABLE moodle_user_map     DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_user_map     DROP COLUMN IF EXISTS created_by;
ALTER TABLE moodle_instances    DROP COLUMN IF EXISTS updated_by;
ALTER TABLE moodle_instances    DROP COLUMN IF EXISTS created_by;

-- ── F5.14 enrolment status CHECK ─────────────────────────────────
ALTER TABLE moodle_enrolments DROP CONSTRAINT IF EXISTS chk_mm_enrolment_status;

-- ── F5.11 token column width ─────────────────────────────────────
-- ** BEFORE this statement, decrypt any v2.0-ciphered tokens.
-- MySQL:       ALTER TABLE moodle_instances MODIFY token VARCHAR(200) NULL;
-- PostgreSQL:  ALTER TABLE moodle_instances ALTER COLUMN token TYPE VARCHAR(200);

-- ── F5.8 UNIQUE on instance name ─────────────────────────────────
ALTER TABLE moodle_instances DROP CONSTRAINT IF EXISTS uniq_mm_instance_name;

-- ── F5.5 idfactura_archived ──────────────────────────────────────
ALTER TABLE moodle_enrolments DROP COLUMN IF EXISTS idfactura_archived;

-- ── F5.3 FK indexes ──────────────────────────────────────────────
DROP INDEX IF EXISTS idx_mm_cert_usermap  ON moodle_certificates;
DROP INDEX IF EXISTS idx_mm_cm_producto   ON moodle_course_map;
DROP INDEX IF EXISTS idx_mm_cm_instance   ON moodle_course_map;
DROP INDEX IF EXISTS idx_mm_enrol_factura ON moodle_enrolments;
DROP INDEX IF EXISTS idx_mm_enrol_cm      ON moodle_enrolments;
DROP INDEX IF EXISTS idx_mm_enrol_contacto ON moodle_enrolments;
DROP INDEX IF EXISTS idx_mm_um_instance   ON moodle_user_map;
DROP INDEX IF EXISTS idx_mm_um_contacto   ON moodle_user_map;

-- ── F7.4 scaffold column on contactos ────────────────────────────
ALTER TABLE contactos DROP COLUMN IF EXISTS mm_last_modified;

-- ── F5.13 schema version table ───────────────────────────────────
-- Leave last so operators can still inspect applied migrations.
DROP TABLE IF EXISTS moodle_schema_version;
