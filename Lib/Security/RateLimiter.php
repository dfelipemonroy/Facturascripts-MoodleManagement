<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Cache\CacheCompat;

/**
 * Fixed-window in-process rate limiter.
 *
 * Designed to throttle endpoints that can be hammered without any
 * cost to the client, like the AJAX search, health check, and
 * certificate PDF download. Uses Tools::cache() as the backing
 * store so multiple PHP processes on the same host coordinate;
 * it is NOT a distributed limiter and does not guarantee strict
 * bucket semantics under heavy concurrency — it is intentionally
 * lightweight. For harder guarantees, swap the cache impl for
 * Redis/Memcached in Fase 10 (F10.9).
 *
 * Usage:
 *
 *   if (! RateLimiter::check($user->nick, 'certificate.download', 30)) {
 *       $response->setStatusCode(429);
 *       return;
 *   }
 *
 * The above permits up to 30 downloads per minute per operator.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F2.10 · §4.12
 */
final class RateLimiter
{
    /** Rolling window length in seconds. */
    private const WINDOW_SECONDS = 60;

    /** Cache key namespace. */
    private const KEY_PREFIX = 'mm:ratelimit:';

    /**
     * Register a single hit and return whether the caller is still
     * within the allowed rate.
     *
     * @param string|int $actor Stable identifier (user nick,
     *                          IP, signed-url tag, …).
     * @param string $action Bucket name, e.g. "pdf.download".
     * @param int $maxPerMinute Allowed hits per 60-second window.
     * @return bool True if under the limit; false
     *              if the caller should be throttled.
     */
    public static function check($actor, string $action, int $maxPerMinute): bool
    {
        if ($maxPerMinute <= 0) {
            return false; // Misconfiguration guard — deny by default.
        }

        $key = self::buildKey($actor, $action);
        $data = CacheCompat::get($key);

        $now = time();
        if (!is_array($data) || ($data['reset'] ?? 0) <= $now) {
            // Fresh window.
            $data = [
                'count' => 1,
                'reset' => $now + self::WINDOW_SECONDS,
            ];
            CacheCompat::set($key, $data, self::WINDOW_SECONDS);
            return true;
        }

        if (($data['count'] ?? 0) >= $maxPerMinute) {
            return false;
        }

        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        $ttl = max(1, $data['reset'] - $now);
        CacheCompat::set($key, $data, $ttl);
        return true;
    }

    /**
     * Introspection: how many hits remain in the current window?
     * Useful for sending "Retry-After" headers.
     *
     * @return array{count:int, limit:int, remaining:int, reset_at:int}
     */
    public static function snapshot($actor, string $action, int $maxPerMinute): array
    {
        $key = self::buildKey($actor, $action);
        $data = CacheCompat::get($key);
        $now = time();
        if (!is_array($data) || ($data['reset'] ?? 0) <= $now) {
            return [
                'count' => 0,
                'limit' => $maxPerMinute,
                'remaining' => $maxPerMinute,
                'reset_at' => $now + self::WINDOW_SECONDS,
            ];
        }
        $count = (int) ($data['count'] ?? 0);
        return [
            'count' => $count,
            'limit' => $maxPerMinute,
            'remaining' => max(0, $maxPerMinute - $count),
            'reset_at' => (int) ($data['reset'] ?? $now + self::WINDOW_SECONDS),
        ];
    }

    /**
     * Admin-facing utility: wipe the current window for an actor.
     * Never call from request handlers — invoked from cron cleanup
     * or CLI support scripts.
     */
    public static function reset($actor, string $action): void
    {
        CacheCompat::delete(self::buildKey($actor, $action));
    }

    private static function buildKey($actor, string $action): string
    {
        $rawActor = is_string($actor) ? $actor : (string) (int) $actor;
        // SEC-11 (2026-04-17) — upgraded from SHA-1 to SHA-256. The
        // hash is only used as a cache-key namespace (not a security
        // primitive) but SHA-1 collisions are now cheap enough that
        // two actors with a colliding prefix could share a bucket.
        // SHA-256 removes the concern at zero runtime cost.
        $hash = substr(hash('sha256', $rawActor), 0, 16);
        // Action is constrained to [a-z0-9._-] by convention; we
        // still normalise here to avoid stray characters in cache keys.
        $actionSafe = preg_replace('/[^a-z0-9._-]+/i', '_', $action);
        return self::KEY_PREFIX . $actionSafe . ':' . $hash;
    }

    private function __construct()
    {
    }
}
