<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Core\Tools;

/**
 * Exponential-backoff retry helper for transient Moodle WS errors.
 *
 * Callers pass a closure that performs a single attempt. The helper
 * invokes it up to `$maxAttempts` times with exponential delay
 * between attempts (baseDelayMs, 2*base, 4*base, ...).
 *
 * The closure signals "retriable failure" by:
 *   - throwing any \Throwable, or
 *   - returning an array whose 'exception' key is set (Moodle WS
 *     error wrapper — same shape MoodleClient::callApi returns).
 *
 * The closure signals "permanent failure" by returning an array
 * whose 'exception' is one of self::PERMANENT_EXCEPTIONS (auth
 * failure, mapping missing, etc.) — RetryPolicy stops retrying
 * immediately.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F6.7 · §1.11
 */
final class RetryPolicy
{
    /**
     * Moodle WS exception tags that should NOT be retried because
     * retrying will never succeed (bad token, capability missing,
     * dml_missing_record, etc.). Any other exception key is treated
     * as transient.
     *
     * @var string[]
     */
    private const PERMANENT_EXCEPTIONS = [
        'invalidtoken',
        'accessexception',
        'webservice_access_exception',
        'dml_missing_record_exception',
        'invalid_parameter_exception',
        'required_capability_exception',
    ];

    /**
     * Run $fn with retry.
     *
     * @param callable $fn () => array|mixed
     * @param int $maxAttempts Total attempts including the first. Clamped to [1, 10].
     * @param int $baseDelayMs Initial pause; doubles each attempt. Clamped to [50, 10000].
     * @param string $tag Used in log lines for correlation.
     * @return mixed The last result of $fn (success or permanent failure).
     */
    public static function execute(
        callable $fn,
        int $maxAttempts = 3,
        int $baseDelayMs = 500,
        string $tag = 'moodle-call'
    ) {
        $maxAttempts = max(1, min($maxAttempts, 10));
        $baseDelayMs = max(50, min($baseDelayMs, 10000));

        $lastResult = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $lastResult = $fn();
            } catch (\Throwable $e) {
                $lastResult = ['exception' => 'throwable', 'message' => $e->getMessage()];
            }

            if (!self::looksLikeFailure($lastResult)) {
                return $lastResult;
            }
            if (self::isPermanent($lastResult)) {
                Tools::log()->warning('retry-policy-permanent', [
                    'tag' => $tag,
                    'attempt' => $attempt,
                    'response' => self::safeSnippet($lastResult),
                ]);
                return $lastResult;
            }
            if ($attempt >= $maxAttempts) {
                Tools::log()->warning('retry-policy-exhausted', [
                    'tag' => $tag,
                    'attempts' => $attempt,
                    'response' => self::safeSnippet($lastResult),
                ]);
                return $lastResult;
            }

            // Transient → wait and retry.
            $delayMs = $baseDelayMs * (int) pow(2, $attempt - 1);
            Tools::log()->info('retry-policy-backoff', [
                'tag' => $tag,
                'attempt' => $attempt,
                'next_wait' => $delayMs,
            ]);
            usleep($delayMs * 1000);
        }

        return $lastResult;
    }

    /**
     * Best-effort heuristic: returns true if the payload looks like
     * an error we should consider retrying.
     */
    private static function looksLikeFailure($value): bool
    {
        return is_array($value) && isset($value['exception']);
    }

    private static function isPermanent($value): bool
    {
        if (!is_array($value) || !isset($value['exception'])) {
            return false;
        }
        $tag = strtolower((string) $value['exception']);
        foreach (self::PERMANENT_EXCEPTIONS as $p) {
            if ($tag === $p || strpos($tag, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function safeSnippet($v): string
    {
        $json = is_array($v) ? json_encode($v) : (string) $v;
        if ($json === false) {
            return '';
        }
        return substr((string) $json, 0, 200);
    }

    private function __construct()
    {
    }
}
