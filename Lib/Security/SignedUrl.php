<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * Generates and verifies HMAC-SHA256 signatures for short-lived URLs.
 *
 * Primary consumer: the certificate PDF download endpoint. When a
 * certificate is issued, the system can email the recipient a link
 * of the form
 *
 *   https://example.com/MoodleCertificatePdf?code=123&exp=1745000000&sig=…
 *
 * The link is valid until `exp` (Unix epoch) and the signature binds
 * the `code` to that expiry so the recipient cannot tamper with
 * either.
 *
 * Security notes:
 *   - Uses hash_hkdf to derive a 32-byte signing key from the FS
 *     cookie secret. This decouples the signing key from any other
 *     use of the original secret and lets the operator rotate keys
 *     by bumping a salt (see deriveKey() below).
 *   - hash_equals() is used for constant-time signature comparison.
 *   - `exp` is mandatory; an unsigned URL is always rejected.
 *   - The HMAC payload is `$resource . '|' . $id . '|' . $exp`
 *     so the same $id under a different $resource (e.g. "certpdf"
 *     vs "reporthtml") cannot replay.
 *
 * The facturascripts authenticated path (F4.1 ownership check) is
 * still the primary authorisation mechanism. Signed URLs are the
 * secondary mechanism used in emails/share links to bypass
 * re-authentication for certificates that have already been issued.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F2.9 · §4.16
 */
final class SignedUrl
{
    /** Signature algorithm. */
    private const ALGO = 'sha256';

    /** Bytes of signing material derived by HKDF. */
    private const KEY_BYTES = 32;

    /**
     * Sign a $resource / $id pair with an expiry (seconds from now).
     *
     * @param string $resource Stable identifier for the endpoint
     *                         ("certificate-pdf", "report-html", ...).
     * @param int    $id       Resource numeric identifier.
     * @param int    $ttlSeconds Lifetime of the signed URL; clamped
     *                           to [60, 60*60*24*30] (1 min - 30 days).
     * @return array{exp:int, sig:string} Signature parts to append
     *                                    to the URL as query string.
     */
    public static function sign(string $resource, int $id, int $ttlSeconds = 3600): array
    {
        $ttlSeconds = max(60, min($ttlSeconds, 60 * 60 * 24 * 30));
        $exp = time() + $ttlSeconds;
        $sig = self::computeSignature($resource, $id, $exp);
        return ['exp' => $exp, 'sig' => $sig];
    }

    /**
     * Verify a signed URL payload.
     *
     * @param string $resource Same string used at sign time.
     * @param int    $id       Resource id.
     * @param int    $exp      `exp` query parameter.
     * @param string $sig      `sig` query parameter.
     * @param int|null $now    Override clock in tests.
     * @return bool            True iff signature is valid AND not expired.
     */
    public static function verify(
        string $resource,
        int $id,
        int $exp,
        string $sig,
        ?int $now = null
    ): bool {
        if ($id <= 0 || $exp <= 0 || $sig === '') {
            return false;
        }
        $clock = $now ?? time();
        if ($clock > $exp) {
            return false;
        }
        $expected = self::computeSignature($resource, $id, $exp);
        return hash_equals($expected, $sig);
    }

    /**
     * Convenience helper: build a complete query string for a URL.
     *
     * Example:
     *   $q = SignedUrl::queryString('certificate-pdf', 42, 86400);
     *   // "code=42&exp=1745000000&sig=abcdef..."
     */
    public static function queryString(string $resource, int $id, int $ttlSeconds = 3600): string
    {
        $parts = self::sign($resource, $id, $ttlSeconds);
        return http_build_query([
            'code' => $id,
            'exp'  => $parts['exp'],
            'sig'  => $parts['sig'],
        ]);
    }

    /**
     * Core signature calculation. Kept private so call sites always
     * go through sign()/verify() which enforce the expiry logic.
     */
    private static function computeSignature(string $resource, int $id, int $exp): string
    {
        $payload = $resource . '|' . $id . '|' . $exp;
        return hash_hmac(self::ALGO, $payload, self::deriveKey());
    }

    /**
     * Derive a signing key from the FS cookie secret using HKDF.
     *
     * If the environment constant is absent (tests), fall back to a
     * static secret — callers should not use SignedUrl in that case.
     */
    private static function deriveKey(): string
    {
        $secret = defined('FS_COOKIES_EXPIRE')
            ? (string) constant('FS_COOKIES_EXPIRE')
            : (string) (getenv('FS_COOKIES_EXPIRE') ?: 'mm-fallback-insecure-secret');

        if ($secret === '') {
            $secret = 'mm-fallback-insecure-secret';
        }

        // Context label lets us rotate keys later without rotating
        // the cookie secret — bump the label here and old URLs
        // become invalid.
        $info = 'mm/signed-url/v1';

        return hash_hkdf(self::ALGO, $secret, self::KEY_BYTES, $info);
    }

    private function __construct()
    {
    }
}
