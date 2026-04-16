<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Enum;

/**
 * Allowed values for `moodle_instances.status`.
 *
 * Populated mainly by the `healthCheck` cron job in Cron.php.
 *
 * @since 2.0
 */
final class InstanceStatus
{
    /** Reachable and responding to `core_webservice_get_site_info`. */
    public const ACTIVE = 'active';

    /** Last healthCheck() failed (connectivity, TLS, token invalid). */
    public const UNREACHABLE = 'unreachable';

    /** Disabled manually by an administrator. */
    public const DISABLED = 'disabled';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [self::ACTIVE, self::UNREACHABLE, self::DISABLED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    private function __construct()
    {
    }
}
