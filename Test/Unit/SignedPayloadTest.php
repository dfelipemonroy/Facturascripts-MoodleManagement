<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\SignedPayload;
use PHPUnit\Framework\TestCase;

/**
 * Covers the SEC-07 (2026-04-17) wizard cookie HMAC wrapper. Verifies
 * pack/unpack round-trip, tamper detection, expiry, context binding
 * and fail-closed behaviour.
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\SignedPayload
 */
final class SignedPayloadTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $data = ['idinstance' => 3, 'codcliente' => 'CLI-42'];
        $packed = SignedPayload::pack($data, 'mm/wizard-prefs/v1', 600);
        self::assertSame($data, SignedPayload::unpack($packed, 'mm/wizard-prefs/v1'));
    }

    public function testDifferentContextRejected(): void
    {
        $packed = SignedPayload::pack(['a' => 1], 'mm/wizard-prefs/v1', 600);
        self::assertNull(
            SignedPayload::unpack($packed, 'mm/other-feature/v1'),
            'SEC-07: context binding must prevent cookie replay across features.'
        );
    }

    public function testTamperedBodyRejected(): void
    {
        $packed = SignedPayload::pack(['idinstance' => 1], 'ctx', 600);
        $parts = explode('.', $packed);
        // Flip a byte in the body, keep the existing MAC.
        $parts[0] = substr($parts[0], 0, -1) . 'X';
        $tampered = implode('.', $parts);
        self::assertNull(SignedPayload::unpack($tampered, 'ctx'));
    }

    public function testTamperedMacRejected(): void
    {
        $packed = SignedPayload::pack(['idinstance' => 1], 'ctx', 600);
        $parts = explode('.', $packed);
        $parts[1] = substr($parts[1], 0, -1) . '0';
        $tampered = implode('.', $parts);
        self::assertNull(SignedPayload::unpack($tampered, 'ctx'));
    }

    public function testExpiredRejected(): void
    {
        $packed = SignedPayload::pack(['x' => 1], 'ctx', 60);
        $future = time() + 120;
        self::assertNull(SignedPayload::unpack($packed, 'ctx', $future));
    }

    public function testMalformedInputReturnsNull(): void
    {
        self::assertNull(SignedPayload::unpack('', 'ctx'));
        self::assertNull(SignedPayload::unpack('no-dots', 'ctx'));
        self::assertNull(SignedPayload::unpack('only.one', 'ctx'));
    }

    public function testEmptyContextAtPackThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        SignedPayload::pack(['x' => 1], '', 60);
    }

    public function testTtlFloorIsSixtySeconds(): void
    {
        $packed = SignedPayload::pack(['x' => 1], 'ctx', 1);
        $parts = explode('.', $packed);
        $exp = (int) $parts[2];
        self::assertGreaterThanOrEqual(time() + 59, $exp);
    }
}
