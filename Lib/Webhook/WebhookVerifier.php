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
     */
    public static function resolveSecret(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        // F5.12 — webhook_secret may be stored cipher-wrapped just like
        // the token. TokenCipher::decrypt() returns the plaintext when
        // the value is wrapped and the same value otherwise.
        try {
            return TokenCipher::decrypt($stored);
        } catch (\Throwable $e) {
            // When decrypt fails we assume the column still holds the
            // plaintext (freshly installed, not yet re-encrypted by
            // the data migration). Returning plaintext is safe here
            // since the caller only uses it as HMAC key material.
            return $stored;
        }
    }

    private function __construct()
    {
    }
}
