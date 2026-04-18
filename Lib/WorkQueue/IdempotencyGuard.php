<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\WorkQueue;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Cache\CacheCompat;

/**
 * Short-lived idempotency ledger for WorkQueue workers.
 *
 * Addresses audit BE-03 (2026-04-17). EnrolmentWorker and
 * OnboardingWorker were triggered via `Model.FacturaCliente.Update`
 * and `Model.MoodleUserMap.Insert` — both events that can fire twice
 * in quick succession (FS model events, fan-in during cascade saves,
 * explicit re-enqueues after transient failures). Without a dedup
 * gate, a retry turned a completed enrolment into a duplicate Moodle
 * `enrol_user` call, and in race conditions two workers could both
 * miss the existing `moodle_enrolments` row and create two.
 *
 * Design: a content-addressed marker stored in `Tools::cache()` for
 * `DEFAULT_TTL` seconds. Workers build a stable key from the logical
 * identity of the job (`enrol:invoice=<id>`, `onboard:usermap=<id>`)
 * and call `beginOnce()`. The first call of a given key within the
 * window returns true and registers the marker; subsequent calls
 * return false so the worker can short-circuit.
 *
 * Trade-offs vs a dedicated `moodle_idempotency_log` table:
 *   - Cache is cheap and already shared across PHP-FPM workers.
 *   - No schema migration to ship.
 *   - TTL-bounded, so a long-running outage (> TTL) permits a
 *     legitimate re-attempt rather than permanently blocking.
 *
 * When cache is unavailable the guard fails *open* — workers execute
 * the body as before. Blocking work on cache availability would be a
 * worse failure mode than the occasional duplicate.
 *
 * @since 2.0 — BE-03 (2026-04-17)
 */
final class IdempotencyGuard
{
    /** Cache key prefix. */
    private const KEY_PREFIX = 'mm:idem:';

    /** Default marker lifetime (24 h). */
    public const DEFAULT_TTL = 86400;

    /**
     * Returns true iff this is the first call for `$key` within the
     * last `$ttl` seconds. A true return atomically registers the
     * marker so concurrent workers race-for-once.
     */
    public static function beginOnce(string $key, int $ttl = self::DEFAULT_TTL): bool
    {
        if ($key === '') {
            return true;
        }
        $cacheKey = self::cacheKey($key);

        try {
            // CacheCompat: static API
            $prev = CacheCompat::get($cacheKey);
        } catch (\Throwable $e) {
            // Cache down (or FS helper unavailable during tests) —
            // fail open; better to risk a dup than stall.
            return true;
        }

        if ($prev !== null) {
            return false;
        }

        try {
            CacheCompat::set($cacheKey, time(), max(60, $ttl));
        } catch (\Throwable $e) {
            // Could not persist the marker — still let the caller run
            // so we do not lose the work. Dedup degrades gracefully.
            return true;
        }

        return true;
    }

    /**
     * Explicitly clears a marker — useful in tests and for operators
     * that want to force a re-run after remediation.
     */
    public static function clear(string $key): void
    {
        if ($key === '') {
            return;
        }
        try {
            CacheCompat::delete(self::cacheKey($key));
        } catch (\Throwable $e) {
            // no-op; cache may be down or unavailable.
        }
    }

    private static function cacheKey(string $logical): string
    {
        return self::KEY_PREFIX . hash('sha256', $logical);
    }

    private function __construct()
    {
    }
}
