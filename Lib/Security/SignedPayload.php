<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * HMAC-SHA256 wrapper for short-lived, tamper-evident payloads stored
 * in places the client can alter (cookies, hidden form fields,
 * localStorage).
 *
 * Wire format:
 *   <base64url(json)>.<hex hmac>.<expiry-unix>
 *
 *   - json is the caller's associative array.
 *   - hmac is SHA256 over `<base64url(json)>|<expiry-unix>` using a
 *     HKDF-derived key bound to the `$context` string so a cookie
 *     signed for context A cannot be replayed into context B.
 *   - expiry-unix lets the caller reject stale payloads without having
 *     to store state server-side.
 *
 * Addresses audit SEC-07 (2026-04-17). The wizard previously dropped
 * a plain JSON cookie (`mm_wizard_prefs`) that an attacker could flip
 * at will — e.g. swap `idinstance` to an instance they do not own and
 * trigger a side-effect through the wizard workflow.
 *
 * Design notes:
 *   - Uses the same HKDF-from-FS_COOKIES_EXPIRE pattern as
 *     `TokenCipher` and `SignedUrl`, so a single deployment secret
 *     seeds every short-lived signing key.
 *   - `$context` is the HKDF info label; include the feature name
 *     (`mm/wizard-prefs/v1`) so rotation is a one-line change.
 *   - Constant-time compare via `hash_equals`.
 *   - Refuses to sign or verify when `FS_COOKIES_EXPIRE` is missing
 *     (same fail-closed posture as SEC-01).
 *
 * @since 2.0 — SEC-07 (2026-04-17)
 */
final class SignedPayload
{
    private const ALGO = 'sha256';
    private const KEY_BYTES = 32;

    /**
     * Seal `$data` with the provided `$context` for up to `$ttlSeconds`.
     *
     * @param array<string, mixed> $data
     * @throws \RuntimeException on missing secret or encode failure.
     */
    public static function pack(array $data, string $context, int $ttlSeconds = 3600): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = self::base64UrlEncode($json);
        $exp = time() + max(60, $ttlSeconds);
        $mac = hash_hmac(self::ALGO, $body . '|' . $exp, self::deriveKey($context));
        return $body . '.' . $mac . '.' . $exp;
    }

    /**
     * Verify and decode a payload produced by `pack()`. Returns null
     * when the signature is invalid, the expiry passed, or the body
     * is not well-formed JSON.
     *
     * @param int|null $now test hook; defaults to `time()`.
     * @return array<string, mixed>|null
     */
    public static function unpack(string $value, string $context, ?int $now = null): ?array
    {
        if ($value === '') {
            return null;
        }
        $parts = explode('.', $value);
        if (count($parts) !== 3) {
            return null;
        }
        [$body, $providedMac, $expStr] = $parts;
        $exp = (int) $expStr;
        $clock = $now ?? time();
        if ($exp <= 0 || $clock > $exp) {
            return null;
        }

        try {
            $expectedMac = hash_hmac(self::ALGO, $body . '|' . $exp, self::deriveKey($context));
        } catch (\Throwable $e) {
            return null;
        }
        if (!hash_equals($expectedMac, $providedMac)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if ($json === false) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    private static function deriveKey(string $context): string
    {
        if ($context === '') {
            throw new \RuntimeException('SignedPayload: empty context.');
        }
        $secret = defined('FS_COOKIES_EXPIRE')
            ? (string) constant('FS_COOKIES_EXPIRE')
            : (string) getenv('FS_COOKIES_EXPIRE');
        if ($secret === '') {
            throw new \RuntimeException(
                'SignedPayload: FS_COOKIES_EXPIRE is not configured. '
                . 'Define it in config.php or the environment.'
            );
        }
        return hash_hkdf(self::ALGO, $secret, self::KEY_BYTES, $context);
    }

    private static function base64UrlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * @return string|false
     */
    private static function base64UrlDecode(string $s)
    {
        $padding = 4 - (strlen($s) % 4);
        if ($padding > 0 && $padding < 4) {
            $s .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    private function __construct()
    {
    }
}
