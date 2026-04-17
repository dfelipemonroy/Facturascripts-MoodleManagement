<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\TokenCipher;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\TokenCipher
 */
final class TokenCipherTest extends TestCase
{
    public function testRoundTripPreservesPlaintext(): void
    {
        $plain = 'moodle-ws-token-1234567890abcdef';
        $cipher = TokenCipher::encrypt($plain);
        self::assertStringStartsWith('mm2g:', $cipher);
        self::assertNotSame($plain, $cipher);
        self::assertSame($plain, TokenCipher::decrypt($cipher));
    }

    public function testDifferentNonceEveryEncrypt(): void
    {
        $plain = 'stable-input';
        $a = TokenCipher::encrypt($plain);
        $b = TokenCipher::encrypt($plain);
        self::assertNotSame($a, $b, 'IV should be random — two encrypts must differ.');
        // both decrypt to same plaintext
        self::assertSame($plain, TokenCipher::decrypt($a));
        self::assertSame($plain, TokenCipher::decrypt($b));
    }

    public function testEncryptIsIdempotentOnAlreadyCipheredValue(): void
    {
        $plain = 'abc';
        $c1 = TokenCipher::encrypt($plain);
        $c2 = TokenCipher::encrypt($c1);
        self::assertSame($c1, $c2);
    }

    public function testDecryptLegacyPlaintextReturnsAsIs(): void
    {
        self::assertSame('legacy-plain', TokenCipher::decrypt('legacy-plain'));
    }

    public function testDecryptNullAndEmpty(): void
    {
        self::assertNull(TokenCipher::decrypt(null));
        self::assertSame('', TokenCipher::decrypt(''));
    }

    public function testTamperedCiphertextReturnsNull(): void
    {
        $plain = 'token-xyz';
        $cipher = TokenCipher::encrypt($plain);
        // Flip a byte in the middle of the base64 payload.
        $body = substr($cipher, 5); // after 'mm2g:'
        $tampered = 'mm2g:' . substr($body, 0, 10) . 'AAAA' . substr($body, 14);
        self::assertNull(TokenCipher::decrypt($tampered));
    }

    public function testIsEncryptedDetection(): void
    {
        self::assertFalse(TokenCipher::isEncrypted(null));
        self::assertFalse(TokenCipher::isEncrypted(''));
        self::assertFalse(TokenCipher::isEncrypted('plain-token'));
        self::assertTrue(TokenCipher::isEncrypted('mm2g:AAAA'));
    }
}
