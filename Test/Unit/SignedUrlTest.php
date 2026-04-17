<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\SignedUrl;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\SignedUrl
 */
final class SignedUrlTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 3600);
        self::assertArrayHasKey('exp', $parts);
        self::assertArrayHasKey('sig', $parts);
        self::assertTrue(
            SignedUrl::verify('certificate-pdf', 42, $parts['exp'], $parts['sig'])
        );
    }

    public function testVerifyRejectsWrongResource(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 3600);
        self::assertFalse(
            SignedUrl::verify('report-pdf', 42, $parts['exp'], $parts['sig'])
        );
    }

    public function testVerifyRejectsWrongId(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 3600);
        self::assertFalse(
            SignedUrl::verify('certificate-pdf', 43, $parts['exp'], $parts['sig'])
        );
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 3600);
        $tampered = substr($parts['sig'], 0, -1) . 'x';
        self::assertFalse(
            SignedUrl::verify('certificate-pdf', 42, $parts['exp'], $tampered)
        );
    }

    public function testVerifyRejectsExpired(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 60);
        $future = time() + 120;
        self::assertFalse(
            SignedUrl::verify('certificate-pdf', 42, $parts['exp'], $parts['sig'], $future)
        );
    }

    public function testTtlIsClamped(): void
    {
        $parts = SignedUrl::sign('certificate-pdf', 42, 10); // below 60s floor
        self::assertGreaterThanOrEqual(time() + 60, $parts['exp']);
    }

    public function testQueryStringIncludesRequiredKeys(): void
    {
        $qs = SignedUrl::queryString('certificate-pdf', 42, 600);
        self::assertStringContainsString('code=42', $qs);
        self::assertStringContainsString('exp=', $qs);
        self::assertStringContainsString('sig=', $qs);
    }
}
