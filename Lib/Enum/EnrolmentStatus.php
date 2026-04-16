<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Enum;

/**
 * Allowed values for `moodle_enrolments.status`.
 *
 * Expressed as final-class string constants (not a PHP 8.1+ enum) so
 * the plugin retains PHP 8.0 compatibility declared in facturascripts.ini.
 *
 * Fase 5 F5.14 backs these values with a database-level ENUM/CHECK
 * constraint; until then the column remains VARCHAR and this class is
 * the single source of truth.
 *
 * @since 2.0
 */
final class EnrolmentStatus
{
    /** New enrolment awaiting payment / confirmation. */
    public const PENDING = 'pending';

    /** User is actively enrolled in the Moodle course. */
    public const ENROLLED = 'enrolled';

    /** Temporarily suspended (e.g. user was unsubscribed but course
     *  completion must still be preserved).
     */
    public const SUSPENDED = 'suspended';

    /** User has been un-enrolled and the Moodle side reflects it. */
    public const UNENROLLED = 'unenrolled';

    /** Enrolment date reached timeend without manual renewal. */
    public const EXPIRED = 'expired';

    /** Enrolment was cancelled before reaching Moodle (admin rollback). */
    public const CANCELLED = 'cancelled';

    /**
     * All valid values in a single array — useful for validation,
     * filter dropdowns, tests.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::ENROLLED,
            self::SUSPENDED,
            self::UNENROLLED,
            self::EXPIRED,
            self::CANCELLED,
        ];
    }

    /**
     * Returns true if the passed value is a valid enrolment status.
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Not instantiable.
     */
    private function __construct()
    {
    }
}
