<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use FacturaScripts\Plugins\MoodleManagement\Lib\CertificatePdfGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.4
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\CertificatePdfGenerator::resolveLogoPath
 *
 * Focus: the path-traversal hardening added in F7.8. Full
 * render-and-hash testing is deferred to a later release since
 * it requires a working Cezpdf dependency + sample templates.
 */
final class CertificatePdfGeneratorTest extends TestCase
{
    /** Invokes the private resolveLogoPath via reflection. */
    private function resolve(string $path)
    {
        $m = new ReflectionMethod(CertificatePdfGenerator::class, 'resolveLogoPath');
        $m->setAccessible(true);
        return $m->invoke(null, $path);
    }

    public function testEmptyPathReturnsNull(): void
    {
        self::assertNull($this->resolve(''));
    }

    public function testNullBytePathReturnsNull(): void
    {
        self::assertNull($this->resolve("valid\0.png"));
    }

    public function testRemoteSchemeReturnsNull(): void
    {
        self::assertNull($this->resolve('http://evil.example.com/logo.png'));
        self::assertNull($this->resolve('https://evil.example.com/logo.png'));
    }

    public function testTraversalRejected(): void
    {
        self::assertNull($this->resolve('../../../etc/passwd'));
        self::assertNull($this->resolve('..\\..\\windows\\system32\\cmd.exe'));
    }

    public function testSvgExtensionRejected(): void
    {
        self::assertNull($this->resolve('logo.svg'));
    }

    public function testUnknownExtensionRejected(): void
    {
        self::assertNull($this->resolve('logo.exe'));
        self::assertNull($this->resolve('logo.php'));
    }

    public function testMissingFileReturnsNull(): void
    {
        // Even with valid extension and no traversal, missing file
        // resolves to null because realpath() returns false.
        self::assertNull($this->resolve('definitely-does-not-exist.png'));
    }
}
