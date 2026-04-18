<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression for audit BE-03 (2026-04-17). The
 * EnrolmentWorker and OnboardingWorker `run()` methods must gate on
 * `IdempotencyGuard::beginOnce` so re-delivered WorkQueue events and
 * FS model-event cascades cannot produce duplicate Moodle-side
 * side-effects (duplicate enrol_user calls, duplicate welcome
 * messages).
 */
final class WorkerIdempotencyTest extends TestCase
{
    private const ENROLMENT_WORKER = __DIR__ . '/../../Worker/EnrolmentWorker.php';
    private const ONBOARDING_WORKER = __DIR__ . '/../../Worker/OnboardingWorker.php';

    public function testEnrolmentWorkerGuardsOnIdempotencyKey(): void
    {
        $src = (string) file_get_contents(self::ENROLMENT_WORKER);
        self::assertStringContainsString(
            'IdempotencyGuard::beginOnce',
            $src,
            'BE-03 regression: EnrolmentWorker::run must call IdempotencyGuard::beginOnce.'
        );
        self::assertStringContainsString(
            'enrol:invoice=',
            $src,
            'BE-03 regression: the key must include the invoice identity.'
        );
        self::assertStringContainsString(
            'pagada=',
            $src,
            'BE-03 regression: the key must include the paid flag so state transitions re-fire.'
        );
    }

    public function testOnboardingWorkerGuardsOnIdempotencyKey(): void
    {
        $src = (string) file_get_contents(self::ONBOARDING_WORKER);
        self::assertStringContainsString(
            'IdempotencyGuard::beginOnce',
            $src,
            'BE-03 regression: OnboardingWorker::run must call IdempotencyGuard::beginOnce.'
        );
        self::assertStringContainsString(
            'onboard:usermap=',
            $src,
            'BE-03 regression: the onboarding key must be scoped to the MoodleUserMap identity.'
        );
    }
}
