<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

/**
 * AES-256-GCM authenticated encryption for Moodle tokens at rest.
 *
 * Addresses audit §4.6 (CRITICAL): tokens were previously stored in
 * plaintext inside `moodle_instances.token`, giving any DB dump or
 * read-only role full control of every linked Moodle instance.
 *
 * Wire format of an encrypted value:
 *   mm2g:<base64url(iv(12) || tag(16) || ciphertext)>
 * The `mm2g:` prefix marks a v2 GCM payload and lets us distinguish
 * stored ciphertext from legacy plaintext during the one-shot
 * migration in F5.12.
 *
 * Key derivation:
 *   HKDF-SHA256 over the FS cookie secret (`FS_COOKIES_EXPIRE`) with
 *   info = "mm/token-cipher/v1". Rotating the info label ("/v2")
 *   allows key rollover without touching the raw secret.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F5.12 · §4.6
 */
final class TokenCipher
{
    /** Marker placed in front of every v2 ciphertext. */
    private const PREFIX = 'mm2g:';

    /** AES-256-GCM = 32 bytes. */
    private const KEY_BYTES = 32;

    /** Recommended IV length for GCM (NIST SP 800-38D). */
    private const IV_BYTES = 12;

    /** Auth tag length. */
    private const TAG_BYTES = 16;

    /**
     * Returns true if the value is an encrypted payload; false if
     * plaintext or null.
     */
    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    /**
     * Encrypts a plaintext token. Idempotent: passing an already
     * encrypted value returns it unchanged.
     *
     * @throws \RuntimeException if OpenSSL is unavailable.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        if (self::isEncrypted($plaintext)) {
            return $plaintext;
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for TokenCipher.');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',                 // no AAD
            self::TAG_BYTES
        );
        if ($cipher === false) {
            throw new \RuntimeException('TokenCipher encrypt failed');
        }

        return self::PREFIX . self::base64UrlEncode($iv . $tag . $cipher);
    }

    /**
     * Decrypts a value previously returned by encrypt(). Passing a
     * legacy plaintext token returns it unchanged so callers can
     * always route through decrypt().
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (!self::isEncrypted($value)) {
            return $value; // legacy plaintext; migration will rewrite.
        }

        $raw = self::base64UrlDecode(substr($value, strlen(self::PREFIX)));
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES + 1) {
            Tools::log()->error('token-cipher-malformed');
            return null;
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $cipher = substr($raw, self::IV_BYTES + self::TAG_BYTES);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            Tools::log()->error('token-cipher-decrypt-failed');
            return null;
        }
        return $plain;
    }

    /**
     * One-shot migration: scan `moodle_instances`, encrypt any row
     * whose token is still in plaintext (detected by missing prefix).
     *
     * Safe to call many times: already-encrypted rows are skipped.
     *
     * @return bool True if the whole batch succeeded.
     */
    public static function encryptExistingRows(?DataBase $db = null): bool
    {
        $db = $db ?? new DataBase();
        $rows = $db->select('SELECT id, token FROM moodle_instances WHERE token IS NOT NULL AND token != \'\'');
        if (empty($rows)) {
            return true;
        }

        $encryptedCount = 0;
        $skippedCount = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $token = (string) $row['token'];
            if (self::isEncrypted($token)) {
                $skippedCount++;
                continue;
            }
            try {
                $ciphered = self::encrypt($token);
            } catch (\Throwable $e) {
                Tools::log()->error('token-cipher-migration-failed', [
                    'instance_id' => $id,
                    'message'     => $e->getMessage(),
                ]);
                return false;
            }
            $sql = 'UPDATE moodle_instances SET token = ' . $db->var2str($ciphered)
                . ' WHERE id = ' . $db->var2str($id);
            if (!$db->exec($sql)) {
                Tools::log()->error('token-cipher-update-failed', ['instance_id' => $id]);
                return false;
            }
            $encryptedCount++;
        }

        Tools::log()->notice('token-cipher-migration-ok', [
            'encrypted' => $encryptedCount,
            'skipped'   => $skippedCount,
        ]);
        return true;
    }

    // ─── Private helpers ──────────────────────────────────────────

    /**
     * HKDF-SHA256 over the FS cookie secret. Rotating the info
     * label rotates the signing key without touching the secret.
     *
     * Fail-closed: if `FS_COOKIES_EXPIRE` is not defined in `config.php`
     * and is not present in the environment, this throws rather than
     * falling back to a hardcoded string. A compromised fallback would
     * allow offline ciphertext recovery by anyone reading the source.
     * See audit SEC-01 (2026-04-17).
     */
    private static function deriveKey(): string
    {
        $secret = defined('FS_COOKIES_EXPIRE')
            ? (string) constant('FS_COOKIES_EXPIRE')
            : (string) getenv('FS_COOKIES_EXPIRE');
        if ($secret === '') {
            throw new \RuntimeException(
                'TokenCipher: FS_COOKIES_EXPIRE is not configured. '
                . 'Define it in config.php or the environment so the '
                . 'token cipher key can be derived. Refusing to encrypt '
                . 'or decrypt with a fallback secret.'
            );
        }
        return hash_hkdf('sha256', $secret, self::KEY_BYTES, 'mm/token-cipher/v1');
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
