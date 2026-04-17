<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleAuditLog;

/**
 * Lightweight audit logger used by security-relevant code paths
 * (IDOR denials, rate-limit hits, password reset, bulk enrol, etc.).
 *
 * Design:
 *   - One static entry point: Audit::record(...).
 *   - Never throws. Any persistence failure is logged to
 *     Tools::log() and swallowed so audit logging cannot break the
 *     user-facing request.
 *   - Payload hash (optional) is sha256 of the caller-provided
 *     structured payload — useful to correlate without storing PII.
 *   - The table must exist — if persistence fails (e.g. during an
 *     upgrade where moodle_audit_log has not yet been created),
 *     we fall back to structured log lines.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F4.4 · §4.18
 */
final class Audit
{
    /**
     * Known outcome tags. Callers should use constants or one of
     * these literals for consistency.
     */
    public const OK            = 'ok';
    public const FORBIDDEN     = 'forbidden';
    public const RATE_LIMITED  = 'rate_limited';
    public const BAD_SIGNATURE = 'bad_signature';
    public const ERROR         = 'error';

    /**
     * Persist one audit row.
     *
     * @param string     $action      Short machine tag, e.g.
     *                                "certificate.download" or
     *                                "usermap.sync-to-moodle".
     * @param string     $outcome     One of the OUTCOME_* constants
     *                                above. Default 'ok'.
     * @param array      $context     {
     *     operator_nick?: string|null,
     *     target_type?:   string|null,
     *     target_id?:     int|null,
     *     ip?:            string|null,
     *     user_agent?:    string|null,
     *     payload?:       mixed       (hashed, NOT stored verbatim)
     * }
     */
    public static function record(string $action, string $outcome = self::OK, array $context = []): void
    {
        try {
            $row = new MoodleAuditLog();
            $row->action = self::clip($action, 80);
            $row->outcome = self::clip($outcome, 20);
            $row->operator_nick = isset($context['operator_nick'])
                ? self::clip((string) $context['operator_nick'], 50)
                : null;
            $row->target_type = isset($context['target_type'])
                ? self::clip((string) $context['target_type'], 50)
                : null;
            $row->target_id = isset($context['target_id']) ? (int) $context['target_id'] : null;
            $row->ip = isset($context['ip'])
                ? self::clip((string) $context['ip'], 45)
                : null;
            $row->user_agent = isset($context['user_agent'])
                ? self::clip((string) $context['user_agent'], 250)
                : null;

            if (array_key_exists('payload', $context)) {
                $row->payload_hash = hash('sha256', (string) json_encode($context['payload']));
            }

            if (!$row->save()) {
                // Persistence failed (table missing or DB down).
                Tools::log()->warning('audit-persistence-failed', [
                    'action'  => $action,
                    'outcome' => $outcome,
                ]);
            }
        } catch (\Throwable $e) {
            // Never break the user request because of audit logging.
            Tools::log()->warning('audit-exception', [
                'action'    => $action,
                'outcome'   => $outcome,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Truncate so CHAR column limits are never violated.
     */
    private static function clip(string $s, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return substr($s, 0, $max);
    }

    private function __construct()
    {
    }
}
