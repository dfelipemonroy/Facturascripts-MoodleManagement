<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Contact;

use FacturaScripts\Core\Base\DataBase;

/**
 * Bumps `contactos.mm_last_modified` without re-triggering the FS
 * `Model.Contacto.Update` event.
 *
 * Addresses audit BE-07 (2026-04-17). The `EditContacto` extension
 * embedded a raw `UPDATE contactos SET mm_last_modified = …` SQL
 * statement inside its `execAfterAction` hook. That inlined both
 * responsibilities (event handler + DB access) in a way that was
 * awkward to unit test, hid the raw SQL in a closure, and prevented
 * reuse from other places that also need to stamp the column
 * (ContactSyncWorker fallback path, future CLI import tools, …).
 *
 * Encapsulates the single column update with explicit safeguards:
 *   - casts the identifier to int before interpolation so no
 *     user-supplied value can reach the SQL string unsanitised
 *   - uses `var2str` for the timestamp literal
 *   - swallows "missing column" errors so the fix is deployable
 *     on installs that have not yet run the v2.0 migration
 *
 * A matching `now()` override lets tests inject a deterministic
 * clock without touching `date()`.
 *
 * @since 2.0 — BE-07 (2026-04-17)
 */
final class ContactTimestampUpdater
{
    /** Column name bumped on every write. */
    public const COLUMN = 'mm_last_modified';

    /**
     * Stamps `contactos.mm_last_modified = now` for `$idcontacto`.
     * Returns true on success, false if the update failed or the
     * column is missing. Never throws.
     */
    public static function touch(int $idcontacto, ?DataBase $db = null, ?\DateTimeInterface $now = null): bool
    {
        if ($idcontacto <= 0) {
            return false;
        }
        $db = $db ?? new DataBase();
        $stamp = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            return $db->exec(
                'UPDATE contactos SET ' . self::COLUMN . ' = ' . $db->var2str($stamp)
                . ' WHERE idcontacto = ' . $idcontacto
            );
        } catch (\Throwable $e) {
            // Column may be absent on installs that haven't run the
            // v2.0 migration yet. Degrade silently so the upstream
            // save is not impacted.
            return false;
        }
    }

    private function __construct()
    {
    }
}
