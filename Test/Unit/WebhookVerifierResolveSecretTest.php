<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\TokenCipher;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\WebhookVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Regression for audit SEC-04 (2026-04-17). `resolveSecret` previously
 * fell back to returning the raw stored value whenever `decrypt` hit
 * an error, which turned a cipher-prefixed secret into usable HMAC key
 * material for anyone holding a DB dump. The fix is to fail-closed on
 * cipher-wrapped values that refuse to decrypt.
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\WebhookVerifier::resolveSecret
 */
final class WebhookVerifierResolveSecretTest extends TestCase
{
    public function testNullReturnsEmpty(): void
    {
        self::assertSame('', WebhookVerifier::resolveSecret(null));
    }

    public function testEmptyReturnsEmpty(): void
    {
        self::assertSame('', WebhookVerifier::resolveSecret(''));
    }

    public function testLegacyPlaintextReturnedAsIs(): void
    {
        self::assertSame(
            'plaintext-legacy-secret',
            WebhookVerifier::resolveSecret('plaintext-legacy-secret')
        );
    }

    public function testValidCiphertextDecrypts(): void
    {
        $plain = 'whk-secret-abc123';
        $cipher = TokenCipher::encrypt($plain);
        self::assertSame($plain, WebhookVerifier::resolveSecret($cipher));
    }

    /**
     * SEC-04 core regression: a cipher-prefixed value that refuses to
     * decrypt (tampered / bad key / corrupted bytes) must NOT surface
     * the raw ciphertext as a usable HMAC key. The helper must return
     * an empty string so `verifySignature` rejects the request.
     */
    public function testCorruptedCipherTextFailsClosed(): void
    {
        $plain = 'whk-secret-xyz';
        $cipher = TokenCipher::encrypt($plain);
        // Flip bytes in the middle so AES-GCM auth tag no longer matches.
        $body = substr($cipher, 5); // strip 'mm2g:' prefix
        $tampered = 'mm2g:' . substr($body, 0, 10) . 'AAAA' . substr($body, 14);

        $out = WebhookVerifier::resolveSecret($tampered);
        self::assertSame(
            '',
            $out,
            'SEC-04: a cipher-prefixed value that refuses to decrypt must return empty, not the ciphertext.'
        );
        self::assertNotSame(
            $tampered,
            $out,
            'SEC-04: the raw ciphertext must never leak back to the caller as HMAC key material.'
        );
    }
}
