<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Cron;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * Cooperative advisory lock for long-running cron jobs.
 *
 * If the FS cron is triggered both by a system crontab and by a
 * hosting-provided HTTP beacon (a very common production setup),
 * two `userSync` jobs can race on the same MoodleUserMap rows and
 * pisar each other's `last_sync`.
 *
 * This class wraps MySQL's `GET_LOCK`/`RELEASE_LOCK` and
 * PostgreSQL's `pg_try_advisory_lock`/`pg_advisory_unlock`. Locks
 * are **session-scoped**: they are released automatically when the
 * DB connection dies, so a crashed cron leaves no sticky state.
 *
 * Usage:
 *   $lock = new Lock();
 *   if (!$lock->acquire('mm.userSync')) {
 *       Tools::log()->info('cron.skip.locked');
 *       return;
 *   }
 *   try {
 *       // ... work ...
 *   } finally {
 *       $lock->release('mm.userSync');
 *   }
 *
 * @since 2.0 — V2.0-ACTION-PLAN F6.4 · §1.7
 */
final class Lock
{
    /** @var DataBase */
    private $db;

    /**
     * Set of names currently held by this instance. Used so
     * release() of an unknown name is silently a no-op, and
     * double-acquire is detected.
     *
     * @var array<string, bool>
     */
    private $held = [];

    public function __construct(?DataBase $db = null)
    {
        $this->db = $db ?? new DataBase();
    }

    /**
     * Try to grab a named lock.
     *
     * @param string $name Lock identifier. Namespaced ("mm.userSync")
     *                     to avoid collisions with other plugins.
     * @param int    $timeoutSec How long to wait when the lock is
     *                           held by another session. Default 0 =
     *                           fail fast so the cron skips rather
     *                           than stacking.
     * @return bool True if acquired, false otherwise.
     */
    public function acquire(string $name, int $timeoutSec = 0): bool
    {
        if (isset($this->held[$name])) {
            return true; // re-entrant OK
        }
        $isPg = strtolower((string) Tools::config('db_type')) === 'postgresql';
        if ($isPg) {
            // Hash the name to a 64-bit int because pg_try_advisory_lock
            // takes bigint. crc32 of a stable prefix+name gives a
            // 32-bit value that we extend to bigint via a cast.
            $hash = sprintf('%u', crc32('mm-cron-lock:' . $name));
            $row = $this->db->select('SELECT pg_try_advisory_lock(' . $hash . '::bigint) AS got');
            $got = !empty($row) && !empty($row[0]['got']);
        } else {
            // MySQL: GET_LOCK(name, timeout) returns 1 / 0 / NULL.
            $sql = 'SELECT GET_LOCK(' . $this->db->var2str($name) . ', ' . (int) $timeoutSec . ') AS got';
            $row = $this->db->select($sql);
            $got = !empty($row) && (int) $row[0]['got'] === 1;
        }
        if ($got) {
            $this->held[$name] = true;
        }
        return $got;
    }

    /**
     * Release a previously acquired lock. Safe to call even if
     * acquire() returned false.
     */
    public function release(string $name): void
    {
        if (!isset($this->held[$name])) {
            return;
        }
        $isPg = strtolower((string) Tools::config('db_type')) === 'postgresql';
        try {
            if ($isPg) {
                $hash = sprintf('%u', crc32('mm-cron-lock:' . $name));
                $this->db->select('SELECT pg_advisory_unlock(' . $hash . '::bigint)');
            } else {
                $this->db->select('SELECT RELEASE_LOCK(' . $this->db->var2str($name) . ')');
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('mm-lock-release-failed', [
                'name'    => $name,
                'message' => $e->getMessage(),
            ]);
        }
        unset($this->held[$name]);
    }

    /**
     * Run $callback only if the lock can be acquired. Guarantees
     * release on exception.
     *
     * @return bool True if the callback ran; false if skipped.
     */
    public function runWithLock(string $name, callable $callback, int $timeoutSec = 0): bool
    {
        if (!$this->acquire($name, $timeoutSec)) {
            return false;
        }
        try {
            $callback();
        } finally {
            $this->release($name);
        }
        return true;
    }
}
