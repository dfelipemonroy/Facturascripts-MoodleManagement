<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Cache;

/**
 * Cross-version cache wrapper.
 *
 * FS < 2025.9 exposed `Tools::cache()` returning an object with
 * `get/set/delete` methods and a per-call TTL. FS 2025.9 removed
 * that helper and introduced a static `Core\Cache` with a fixed
 * `EXPIRATION` constant (1 hour at the time of writing) and no
 * per-entry TTL.
 *
 * This wrapper keeps the plugin's existing `set($key, $value, $ttl)`
 * contract by serialising `{v: value, exp: unix-ts}` inside the
 * FS cache entry. On `get`, entries past their embedded expiry are
 * treated as miss and lazy-deleted. `has` honours the same check.
 *
 * Strategy per FS version is picked once at class-load time:
 *   - `Core\Cache` present → new static API.
 *   - Fallback to `Tools::cache()` → legacy object API (pre-2025.9).
 *
 * The wrapper never throws; every operation degrades silently when
 * FS has neither helper available (e.g. during test bootstrap).
 *
 * @since 2.0 — 2026-04-19 (FS 2025.9 compat)
 */
final class CacheCompat
{
    /**
     * Pull the cached value, or return $default on miss / expiry.
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $raw = self::rawGet($key);
        if ($raw === null) {
            return $default;
        }
        if (is_array($raw) && isset($raw['__mm_v']) && isset($raw['__mm_exp'])) {
            if ((int) $raw['__mm_exp'] <= time()) {
                self::delete($key);
                return $default;
            }
            return $raw['__mm_v'];
        }
        // Legacy value stored by pre-wrapper code paths; return as-is.
        return $raw;
    }

    /**
     * Store a value with a soft TTL enforced on read. Returns true
     * on success, false when no cache backend is available.
     *
     * @param mixed $value
     */
    public static function set(string $key, $value, int $ttlSeconds): bool
    {
        $envelope = [
            '__mm_v' => $value,
            '__mm_exp' => time() + max(1, $ttlSeconds),
        ];
        return self::rawSet($key, $envelope);
    }

    public static function has(string $key): bool
    {
        $raw = self::rawGet($key);
        if ($raw === null) {
            return false;
        }
        if (is_array($raw) && isset($raw['__mm_exp'])) {
            if ((int) $raw['__mm_exp'] <= time()) {
                self::delete($key);
                return false;
            }
            return true;
        }
        return true;
    }

    public static function delete(string $key): void
    {
        try {
            if (class_exists(\FacturaScripts\Core\Cache::class, false) || class_exists(\FacturaScripts\Core\Cache::class)) {
                \FacturaScripts\Core\Cache::delete($key);
                return;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        try {
            if (method_exists(\FacturaScripts\Core\Tools::class, 'cache')) {
                \FacturaScripts\Core\Tools::cache()->delete($key);
            }
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * @return mixed|null The stored payload, or null on miss.
     */
    private static function rawGet(string $key)
    {
        try {
            if (class_exists(\FacturaScripts\Core\Cache::class)) {
                return \FacturaScripts\Core\Cache::get($key);
            }
        } catch (\Throwable $e) {
            // fall through to legacy path
        }
        try {
            if (method_exists(\FacturaScripts\Core\Tools::class, 'cache')) {
                return \FacturaScripts\Core\Tools::cache()->get($key);
            }
        } catch (\Throwable $e) {
            // no-op
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private static function rawSet(string $key, $value): bool
    {
        try {
            if (class_exists(\FacturaScripts\Core\Cache::class)) {
                \FacturaScripts\Core\Cache::set($key, $value);
                return true;
            }
        } catch (\Throwable $e) {
            // fall through to legacy path
        }
        try {
            if (method_exists(\FacturaScripts\Core\Tools::class, 'cache')) {
                \FacturaScripts\Core\Tools::cache()->set($key, $value);
                return true;
            }
        } catch (\Throwable $e) {
            // no-op
        }
        return false;
    }

    private function __construct()
    {
    }
}
