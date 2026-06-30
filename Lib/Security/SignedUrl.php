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
 *   https://example.com/MoodleCertificatePdf?code=123&exp=1745000000&v=1&sig=…
 *
 * The link is valid until `exp` (Unix epoch) and the signature binds
 * the `code` to that expiry so the recipient cannot tamper with
 * either.
 *
 * Security notes:
 *   - Uses hash_hkdf to derive a 32-byte signing key from the FS
 *     cookie secret. The HKDF `info` label is version-tagged so
 *     operators can rotate keys without invalidating in-flight URLs
 *     (SEC-05, 2026-04-17).
 *   - hash_equals() is used for constant-time signature comparison.
 *   - `exp` is mandatory; an unsigned URL is always rejected.
 *   - The HMAC payload is `$resource . '|' . $id . '|' . $exp . '|' . $version`
 *     so the same $id under a different $resource (e.g. "certpdf"
 *     vs "reporthtml") cannot replay AND a URL signed with an older
 *     key version cannot be re-used against a newer one.
 *
 * The facturascripts authenticated path (F4.1 ownership check) is
 * still the primary authorisation mechanism. Signed URLs are the
 * secondary mechanism used in emails/share links to bypass
 * re-authentication for certificates that have already been issued.
 *
 * Key rotation playbook (SEC-05):
 *   1. Add a new entry to `KEY_VERSIONS` with a fresh info label
 *      (e.g. `mm/signed-url/v2`).
 *   2. Bump `CURRENT_VERSION` to the new number.
 *   3. Deploy. New URLs are signed with v2; URLs already in the wild
 *      with `v=1` continue to verify against the v1 info label until
 *      their `exp` passes.
 *   4. After the longest legitimate TTL (default 30 days) drop the
 *      v1 entry to finalise the rotation.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F2.9 · §4.16 · rev SEC-05 (2026-04-17)
 */
final class SignedUrl
{
    /** Signature algorithm. */
    private const ALGO = 'sha256';

    /** Bytes of signing material derived by HKDF. */
    private const KEY_BYTES = 32;

    /**
     * Version number emitted in newly signed URLs. Older versions
     * listed in `KEY_VERSIONS` keep verifying until their `exp`.
     */
    public const CURRENT_VERSION = 1;

    /**
     * Version → HKDF info label lookup. Adding a version here with
     * a fresh label begins the rotation window; removing it ends it.
     *
     * @var array<int, string>
     */
    private const KEY_VERSIONS = [
        1 => 'mm/signed-url/v1',
    ];

    /**
     * Sign a $resource / $id pair with an expiry (seconds from now).
     *
     * @param string $resource Stable identifier for the endpoint
     *                         ("certificate-pdf", "report-html", ...).
     * @param int $id Resource numeric identifier.
     * @param int $ttlSeconds Lifetime of the signed URL; clamped
     *                        to [60, 60*60*24*30] (1 min - 30 days).
     * @return array{exp:int, v:int, sig:string} Signature parts to
     *                                           append to the URL.
     */
    public static function sign(string $resource, int $id, int $ttlSeconds = 3600): array
    {
        $ttlSeconds = max(60, min($ttlSeconds, 60 * 60 * 24 * 30));
        $exp = time() + $ttlSeconds;
        $version = self::CURRENT_VERSION;
        $sig = self::computeSignature($resource, $id, $exp, $version);
        return ['exp' => $exp, 'v' => $version, 'sig' => $sig];
    }

    /**
     * Verify a signed URL payload.
     *
     * @param string $resource Same string used at sign time.
     * @param int $id Resource id.
     * @param int $exp `exp` query parameter.
     * @param string $sig `sig` query parameter.
     * @param int|null $now Override clock in tests.
     * @param int $version `v` query parameter. Defaults to 1 so
     *                     pre-SEC-05 URLs (no `v=`) keep working.
     * @return bool True iff signature is valid AND not expired.
     */
    public static function verify(
        string $resource,
        int $id,
        int $exp,
        string $sig,
        ?int $now = null,
        int $version = 1
    ): bool {
        if ($id <= 0 || $exp <= 0 || $sig === '') {
            return false;
        }
        if (!isset(self::KEY_VERSIONS[$version])) {
            return false;
        }
        $clock = $now ?? time();
        if ($clock > $exp) {
            return false;
        }
        $expected = self::computeSignature($resource, $id, $exp, $version);
        return hash_equals($expected, $sig);
    }

    /**
     * Convenience helper: build a complete query string for a URL.
     *
     * Example:
     *   $q = SignedUrl::queryString('certificate-pdf', 42, 86400);
     *   // "code=42&exp=1745000000&v=1&sig=abcdef..."
     */
    public static function queryString(string $resource, int $id, int $ttlSeconds = 3600): string
    {
        $parts = self::sign($resource, $id, $ttlSeconds);
        return http_build_query([
            'code' => $id,
            'exp' => $parts['exp'],
            'v' => $parts['v'],
            'sig' => $parts['sig'],
        ]);
    }

    /**
     * Core signature calculation. Kept private so call sites always
     * go through sign()/verify() which enforce the expiry + version
     * logic.
     */
    private static function computeSignature(string $resource, int $id, int $exp, int $version): string
    {
        $payload = $resource . '|' . $id . '|' . $exp . '|' . $version;
        return hash_hmac(self::ALGO, $payload, self::deriveKey($version));
    }

    /**
     * Derive a signing key from the FS cookie secret using HKDF,
     * with a version-tagged info label so rotating the version
     * invalidates nothing in flight.
     *
     * SEC-01 / SEC-05 (2026-04-17) — fail-closed when
     * `FS_COOKIES_EXPIRE` is absent (see TokenCipher for the same
     * posture). Never fall back to a hardcoded string.
     */
    private static function deriveKey(int $version): string
    {
        $info = self::KEY_VERSIONS[$version] ?? null;
        if ($info === null) {
            throw new \RuntimeException('SignedUrl: unknown key version ' . $version);
        }

        $secret = defined('FS_COOKIES_EXPIRE')
            ? (string) constant('FS_COOKIES_EXPIRE')
            : (string) getenv('FS_COOKIES_EXPIRE');

        if ($secret === '') {
            throw new \RuntimeException(
                'SignedUrl: FS_COOKIES_EXPIRE is not configured. '
                . 'Define it in config.php or the environment.'
            );
        }

        return hash_hkdf(self::ALGO, $secret, self::KEY_BYTES, $info);
    }

    private function __construct()
    {
    }
}
