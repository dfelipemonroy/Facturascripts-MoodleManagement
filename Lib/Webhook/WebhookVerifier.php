<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\TokenCipher;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Stateless helpers to validate an inbound Moodle webhook request.
 *
 * Three independent checks:
 *   1. HMAC-SHA256 signature of the raw body using the per-instance
 *      shared secret (stored encrypted in moodle_instances.webhook_secret).
 *   2. Timestamp freshness — reject when delivered more than 5 minutes
 *      after the claimed t= value to limit replay windows.
 *   3. Nonce uniqueness — reject a nonce seen within the last hour to
 *      close the residual replay window.
 *
 * All three are independently recorded on MoodleWebhookLog so
 * operators can diagnose misconfiguration (typical: clock skew).
 */
final class WebhookVerifier
{
    /** Max accepted clock skew between Moodle and FS (seconds). */
    public const TIMESTAMP_WINDOW = 300;

    /** Cache TTL for seen nonces (seconds). */
    public const NONCE_TTL = 3600;

    /** Cache key prefix for replay protection. */
    private const NONCE_CACHE_PREFIX = 'mm_webhook_nonce_';

    /**
     * Compare two HMAC hex strings in constant time.
     * Returns false if lengths differ without leaking timing.
     */
    public static function verifySignature(string $rawBody, string $secret, string $providedHex): bool
    {
        if ($secret === '' || $providedHex === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, strtolower($providedHex));
    }

    /**
     * Returns true when $timestamp is within TIMESTAMP_WINDOW of the
     * server clock. Zero / negative values are rejected.
     */
    public static function verifyTimestamp(int $timestamp, ?int $now = null): bool
    {
        if ($timestamp <= 0) {
            return false;
        }
        $now = $now ?? time();
        return abs($now - $timestamp) <= self::TIMESTAMP_WINDOW;
    }

    /**
     * Nonce replay check, backed by Tools::cache() so it works across
     * PHP-FPM workers. First call for a given nonce stores a marker
     * and returns true; subsequent calls return false until the TTL
     * expires.
     *
     * The nonce is the opaque string provided by Moodle — we only
     * trust it after verifySignature() has already passed, otherwise
     * an attacker could exhaust the cache by sending random nonces.
     */
    public static function registerNonce(string $nonce): bool
    {
        if ($nonce === '' || strlen($nonce) > 128) {
            return false;
        }
        $key = self::NONCE_CACHE_PREFIX . hash('sha256', $nonce);
        $cache = Tools::cache();
        $prev = $cache->get($key);
        if ($prev !== null) {
            return false; // already seen, replay
        }
        $cache->set($key, 1, self::NONCE_TTL);
        return true;
    }

    /**
     * Resolve the per-instance webhook secret, handling the TokenCipher
     * ciphertext transparently (same encryption scheme as moodle_instances.token).
     *
     * SEC-04 (2026-04-17) — previously, any failure inside `decrypt`
     * (corrupted ciphertext, key rotation, TypeError from a null return)
     * was swallowed and the caller received the raw stored value back.
     * When that value is an `mm2g:` ciphertext, anyone holding a DB
     * dump could use it as the HMAC key material and forge signatures.
     * Fail-closed now: a decrypt failure on a cipher-prefixed value
     * returns an empty secret (`verifySignature` then rejects the
     * request) and records the incident so operators can rotate.
     */
    public static function resolveSecret(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        // Legacy plaintext (pre F5.12 migration): return as-is. Safe
        // because the value was only ever used as HMAC key material
        // and is NOT persisted outside the `moodle_instances` row.
        if (!TokenCipher::isEncrypted($stored)) {
            return $stored;
        }

        try {
            $plain = TokenCipher::decrypt($stored);
        } catch (\Throwable $e) {
            Tools::log()->error('webhook-secret-decrypt-threw', [
                'message' => $e->getMessage(),
            ]);
            return '';
        }

        if ($plain === null || $plain === '') {
            Tools::log()->error('webhook-secret-decrypt-failed');
            return '';
        }
        return $plain;
    }

    private function __construct()
    {
    }
}
