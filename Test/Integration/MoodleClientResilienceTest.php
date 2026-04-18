<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Regression for audit BE-02 (2026-04-17). `RetryPolicy` and
 * `CircuitBreaker` existed since Fase 6 but were dead code —
 * `MoodleClient::callApi` never referenced either class. This suite
 * asserts, source-level, that the wiring is in place so refactors
 * cannot silently regress the transient-failure handling.
 */
final class MoodleClientResilienceTest extends TestCase
{
    private const CLIENT_SOURCE = __DIR__ . '/../../Lib/MoodleClient.php';

    public function testCallApiGuardsOnCircuitBreaker(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        self::assertStringContainsString(
            'CircuitBreaker::allow',
            $source,
            'BE-02 regression: callApi must check CircuitBreaker::allow before dispatching.'
        );
        self::assertStringContainsString(
            "'exception' => 'circuit_open'",
            $source,
            'BE-02 regression: an OPEN breaker must surface `circuit_open` to callers.'
        );
    }

    public function testCallApiReportsOutcomeToBreaker(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        self::assertStringContainsString(
            'CircuitBreaker::reportFailure',
            $source,
            'BE-02 regression: failed dispatches must feed the breaker.'
        );
        self::assertStringContainsString(
            'CircuitBreaker::reportSuccess',
            $source,
            'BE-02 regression: successful dispatches must reset the breaker.'
        );
    }

    public function testCallApiRunsThroughRetryPolicy(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        self::assertStringContainsString(
            'RetryPolicy::execute',
            $source,
            'BE-02 regression: dispatch must route through RetryPolicy::execute.'
        );
        self::assertStringContainsString(
            'retry_max_attempts',
            $source,
            'BE-02 regression: callers must be able to tune retry attempts through options.'
        );
    }

    public function testDispatchHasBeenExtracted(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        self::assertMatchesRegularExpression(
            '/private\s+static\s+function\s+dispatchSingle/',
            $source,
            'BE-02 regression: the raw transport must live in `dispatchSingle` so retry can loop over it.'
        );
        self::assertMatchesRegularExpression(
            '/private\s+static\s+function\s+dispatchWithRetry/',
            $source,
            'BE-02 regression: the retry-wrapped dispatcher must stay addressable.'
        );
    }

    public function testInfrastructureRejectionsDoNotCountAsFailures(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        foreach (['ssrf_rejected', 'insecure_transport', 'circuit_open'] as $tag) {
            self::assertStringContainsString(
                "'{$tag}'",
                $source,
                sprintf('BE-02 regression: %s must be excluded from the failure count.', $tag)
            );
        }
    }

    /**
     * BE-05 regression (2026-04-17) — a 200 OK with a non-empty
     * `warnings` array must surface as a partial failure to callers
     * that opt in via `fail_on_partial`, and must always land in the
     * log.
     */
    public function testPartialFailuresAreSurfaced(): void
    {
        $source = (string) file_get_contents(self::CLIENT_SOURCE);
        self::assertStringContainsString(
            'moodle-ws-partial-failures',
            $source,
            'BE-05 regression: partial failures must hit the log with a dedicated tag.'
        );
        self::assertStringContainsString(
            "'partial_failure'",
            $source,
            'BE-05 regression: `fail_on_partial` callers must receive `exception = partial_failure`.'
        );
        self::assertStringContainsString(
            'fail_on_partial',
            $source,
            'BE-05 regression: callers must be able to opt in via $options[fail_on_partial].'
        );
    }
}
