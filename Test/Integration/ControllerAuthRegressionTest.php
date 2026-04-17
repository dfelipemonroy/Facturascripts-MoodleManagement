<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.8
 *
 * Structural regression tests for F4.1 (IDOR on MoodleCertificatePdf)
 * and F4.2 (ownership guard on EditMoodleUserMap). A full HTTP test
 * would require a booted FS request/response stack; a structural
 * assertion guarantees the guards cannot be accidentally removed
 * in refactors.
 */
final class ControllerAuthRegressionTest extends TestCase
{
    public function testMoodleCertificatePdfHasIsAuthorisedMethod(): void
    {
        $r = new ReflectionClass(
            \FacturaScripts\Plugins\MoodleManagement\Controller\MoodleCertificatePdf::class
        );
        self::assertTrue(
            $r->hasMethod('privateCore') || $r->hasMethod('publicCore'),
            'Certificate controller must expose private or public core.'
        );
        // isAuthorised is private; verify it exists via reflection.
        self::assertTrue(
            $r->hasMethod('isAuthorised'),
            'F4.1 regression: isAuthorised() helper must exist.'
        );
    }

    public function testEditMoodleUserMapDeclaresGuardedActions(): void
    {
        $r = new ReflectionClass(
            \FacturaScripts\Plugins\MoodleManagement\Controller\EditMoodleUserMap::class
        );
        self::assertTrue(
            $r->hasConstant('GUARDED_ACTIONS'),
            'F4.2 regression: GUARDED_ACTIONS must exist.'
        );
        self::assertTrue(
            $r->hasConstant('ACTION_RATE_LIMITS'),
            'F4.3 regression: ACTION_RATE_LIMITS must exist.'
        );
        self::assertTrue(
            $r->hasMethod('canManageCurrentUserMap'),
            'F4.2 regression: canManageCurrentUserMap() helper must exist.'
        );
    }

    public function testCertificatePdfCarriesSignedResourceTag(): void
    {
        self::assertSame(
            'certificate-pdf',
            \FacturaScripts\Plugins\MoodleManagement\Controller\MoodleCertificatePdf::SIGNED_RESOURCE,
            'F2.9 regression: SignedUrl resource tag must remain stable.'
        );
    }
}
