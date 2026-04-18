<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Model;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F13 · DISCOVERED-02
 *
 * Intercepts Delete on models that declare a `deleted_at` column
 * (added in F5.20) and turns it into a soft-delete. The row stays
 * in the table with `deleted_at = NOW()` so the papelera
 * (ListMoodleTrash, F10.4) can surface it for restore or purge.
 *
 * Design
 *   - Models opt in by `use SoftDeleteTrait` AND declaring
 *     `public $deleted_at`. Models without the column behave
 *     normally (no-op wrapper, falls through to physical delete).
 *   - The trait overrides `delete()` only — `clear()`, `save()`,
 *     `test()` etc. are untouched.
 *   - Writes deleted_at via a raw UPDATE to avoid firing
 *     Model.<X>.Update events (which would cascade into workers
 *     that already consume .Delete).
 *   - `restore()` convenience method clears `deleted_at` in place,
 *     also via raw UPDATE.
 *   - `forcePhysicalDelete()` escape hatch for admin cleanup
 *     scripts that genuinely need DELETE (the papelera purge
 *     button uses this).
 *
 * NOT thread-safe on concurrent restore/purge — acceptable because
 * only admins hit those paths and the audit log (F4.4) records
 * every action.
 */
trait SoftDeleteTrait
{
    /**
     * Soft-delete override. Sets `deleted_at = NOW()` via a raw
     * UPDATE. If the model is already soft-deleted, the call
     * is a no-op and returns true.
     *
     * Falls through to the parent implementation when the model
     * doesn't actually have a `deleted_at` column (defensive —
     * should never happen once F5.20 ran).
     */
    public function delete(): bool
    {
        if (!property_exists($this, 'deleted_at')) {
            return parent::delete();
        }
        $pk = static::primaryColumn();
        // DB-02 (2026-04-17) — refuse composite primary keys. The raw
        // UPDATE below embeds a single `$pk = value` condition, so a
        // composite PK returned as an array would quietly target the
        // wrong row (or fail at SQL level). Surface the mismatch now
        // so callers move the model onto the soft-delete trait with
        // eyes open.
        if (is_array($pk)) {
            throw new \FacturaScripts\Plugins\MoodleManagement\Lib\Exception\UnsupportedSchemaException(
                'SoftDeleteTrait: composite primary keys are not supported. '
                . 'Table ' . static::tableName() . ' declares ' . implode(',', $pk) . '.'
            );
        }
        $id = $this->{$pk} ?? null;
        if ($id === null) {
            return false;
        }
        if (!empty($this->deleted_at)) {
            return true; // already soft-deleted
        }
        $db = new DataBase();
        $now = $db->var2str(date('Y-m-d H:i:s'));
        $sql = 'UPDATE ' . static::tableName()
            . ' SET deleted_at = ' . $now
            . ' WHERE ' . $pk . ' = ' . $db->var2str($id);
        if (!$db->exec($sql)) {
            Tools::log()->warning('mm-soft-delete-failed', [
                'table' => static::tableName(),
                'id'    => $id,
            ]);
            return false;
        }
        $this->deleted_at = date('Y-m-d H:i:s');
        return true;
    }

    /**
     * Clears `deleted_at` in place. Returns false if the row
     * is not soft-deleted.
     */
    public function restore(): bool
    {
        if (!property_exists($this, 'deleted_at') || empty($this->deleted_at)) {
            return false;
        }
        $pk = static::primaryColumn();
        $id = $this->{$pk} ?? null;
        if ($id === null) {
            return false;
        }
        $db = new DataBase();
        $sql = 'UPDATE ' . static::tableName()
            . ' SET deleted_at = NULL'
            . ' WHERE ' . $pk . ' = ' . $db->var2str($id);
        if (!$db->exec($sql)) {
            return false;
        }
        $this->deleted_at = null;
        return true;
    }

    /**
     * Escape hatch — calls parent::delete() bypassing the
     * soft-delete override. Intended for the papelera purge
     * button and admin cleanup scripts ONLY.
     */
    public function forcePhysicalDelete(): bool
    {
        return parent::delete();
    }

    /**
     * Convenience: true when the model has been soft-deleted
     * but not yet purged.
     */
    public function isTrashed(): bool
    {
        return property_exists($this, 'deleted_at') && !empty($this->deleted_at);
    }
}
