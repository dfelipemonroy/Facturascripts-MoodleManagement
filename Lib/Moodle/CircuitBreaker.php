<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Core\Tools;

/**
 * Lightweight per-instance circuit breaker.
 *
 * Model: three states — CLOSED, OPEN, HALF_OPEN — stored in
 * Tools::cache() so coordination across PHP-FPM workers is free.
 *
 * Transitions:
 *   CLOSED + reportFailure() × failureThreshold within the window
 *     → OPEN for $cooldownSec seconds.
 *   OPEN (expired)
 *     → HALF_OPEN: next request allowed through.
 *   HALF_OPEN + reportSuccess()
 *     → CLOSED, counter reset.
 *   HALF_OPEN + reportFailure()
 *     → OPEN again (fresh cooldown).
 *
 * Keys are namespaced by instance id so a single flaky Moodle
 * site does not disable the whole plugin.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F6.7 · §1.11
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half-open';

    private const KEY_PREFIX = 'mm:cb:';

    /** @var int Failures within a rolling 5-minute window that trip the breaker. */
    public const DEFAULT_FAILURE_THRESHOLD = 5;

    /** @var int Seconds the breaker stays OPEN before promoting to HALF_OPEN. */
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    /** @var int Rolling failure-count window. */
    public const DEFAULT_WINDOW_SECONDS = 300;

    /**
     * True if the caller should attempt the remote call right now.
     */
    public static function allow(int $instanceId): bool
    {
        $state = self::read($instanceId);
        if (!$state) {
            return true;
        }
        if ($state['status'] === self::STATE_CLOSED) {
            return true;
        }
        if ($state['status'] === self::STATE_OPEN) {
            $now = time();
            if ($now >= ($state['reset_at'] ?? 0)) {
                $state['status'] = self::STATE_HALF_OPEN;
                self::write($instanceId, $state);
                return true;
            }
            return false;
        }
        // HALF_OPEN: allow exactly one probe; the next report*() call
        // transitions the breaker definitively.
        return true;
    }

    /**
     * Record a successful call. Closes the breaker when in HALF_OPEN.
     */
    public static function reportSuccess(int $instanceId): void
    {
        $state = self::read($instanceId);
        if (!$state) {
            return;
        }
        if ($state['status'] !== self::STATE_CLOSED) {
            self::write($instanceId, [
                'status' => self::STATE_CLOSED,
                'failures' => 0,
                'window_from' => time(),
            ]);
            Tools::log()->notice('circuit-breaker-closed', ['instance' => $instanceId]);
        }
    }

    /**
     * Record a failed call.
     */
    public static function reportFailure(
        int $instanceId,
        int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        int $cooldownSec = self::DEFAULT_COOLDOWN_SECONDS,
        int $windowSec = self::DEFAULT_WINDOW_SECONDS
    ): void {
        $state = self::read($instanceId) ?: [
            'status' => self::STATE_CLOSED,
            'failures' => 0,
            'window_from' => time(),
        ];

        if ($state['status'] === self::STATE_HALF_OPEN) {
            // Probe failed → back to OPEN with fresh cooldown.
            self::write($instanceId, [
                'status' => self::STATE_OPEN,
                'failures' => $failureThreshold,
                'reset_at' => time() + $cooldownSec,
            ]);
            Tools::log()->warning('circuit-breaker-reopened', ['instance' => $instanceId]);
            return;
        }

        $now = time();
        $windowFrom = (int) ($state['window_from'] ?? $now);
        if ($now - $windowFrom > $windowSec) {
            $state['window_from'] = $now;
            $state['failures'] = 0;
        }
        $state['failures'] = ((int) ($state['failures'] ?? 0)) + 1;

        if ($state['failures'] >= $failureThreshold) {
            self::write($instanceId, [
                'status' => self::STATE_OPEN,
                'failures' => $state['failures'],
                'reset_at' => $now + $cooldownSec,
            ]);
            Tools::log()->warning('circuit-breaker-tripped', [
                'instance' => $instanceId,
                'failures' => $state['failures'],
                'cooldown' => $cooldownSec,
            ]);
            return;
        }

        self::write($instanceId, $state);
    }

    private static function read(int $instanceId): ?array
    {
        $data = Tools::cache()->get(self::KEY_PREFIX . $instanceId);
        return is_array($data) ? $data : null;
    }

    private static function write(int $instanceId, array $state): void
    {
        Tools::cache()->set(self::KEY_PREFIX . $instanceId, $state, self::DEFAULT_WINDOW_SECONDS * 2);
    }

    private function __construct()
    {
    }
}
