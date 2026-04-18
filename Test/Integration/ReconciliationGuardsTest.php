<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression tests for audit BE-04 (2026-04-17).
 * Reconciliation used to flip every local `enrolled` row to
 * `unenrolled` whenever `getEnrolledUsers` returned an empty list,
 * which could happen on a silent WS failure (expired token, 200 with
 * empty body, partial outage). Two guards now stand between that
 * response and the bulk write:
 *
 *   - preflight health probe at the top of `reconcileEnrolments`
 *   - per-course health re-probe before acting on empty responses
 *
 * Both are asserted at the source level so refactors cannot drop
 * them silently.
 */
final class ReconciliationGuardsTest extends TestCase
{
    private const CRON_SOURCE = __DIR__ . '/../../Cron.php';

    public function testReconcileEnrolmentsHasPreflightHealthProbe(): void
    {
        $source = $this->readReconcileEnrolments();
        self::assertStringContainsString(
            'MoodleClient::testConnection($instance)',
            $source,
            'BE-04 regression: reconcileEnrolments must probe the instance before paginating course maps.'
        );
        self::assertStringContainsString(
            'reconcile-skipped-unhealthy-instance',
            $source,
            'BE-04 regression: preflight failure must log `reconcile-skipped-unhealthy-instance`.'
        );
    }

    public function testReconcileEnrolmentsGuardsEmptyResponses(): void
    {
        $source = $this->readReconcileEnrolments();
        self::assertStringContainsString(
            'reconciliationProbeHealthy',
            $source,
            'BE-04 regression: the empty-response branch must re-probe before mass-unenrolling.'
        );
        self::assertStringContainsString(
            'reconcile-skipped-empty-suspect',
            $source,
            'BE-04 regression: a failing re-probe must log `reconcile-skipped-empty-suspect` and skip the course.'
        );
    }

    public function testReconciliationProbeHealthyHelperExists(): void
    {
        $full = (string) file_get_contents(self::CRON_SOURCE);
        self::assertMatchesRegularExpression(
            '/private\s+static\s+function\s+reconciliationProbeHealthy/',
            $full,
            'BE-04 regression: reconciliationProbeHealthy helper must be declared on Cron.'
        );
    }

    private function readReconcileEnrolments(): string
    {
        $full = (string) file_get_contents(self::CRON_SOURCE);
        $start = strpos($full, 'private function reconcileEnrolments');
        self::assertNotFalse($start, 'Cron::reconcileEnrolments must exist.');
        // The helper block that follows starts with the BE-04 doc comment.
        $end = strpos($full, 'BE-04 (2026-04-17) — cheap health probe', $start);
        self::assertNotFalse($end, 'BE-04 helper block must follow reconcileEnrolments.');
        return substr($full, $start, $end - $start);
    }
}
