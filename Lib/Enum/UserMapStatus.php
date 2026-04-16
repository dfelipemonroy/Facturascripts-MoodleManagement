<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Enum;

/**
 * Allowed values for `moodle_user_map.status`.
 *
 * At present the plugin relies on `moodle_userid > 0` as an implicit
 * "mapped" flag. Fase 5 F5.14 introduces this explicit column so the
 * lifecycle (pending, mapped, suspended, unlinked) is first-class.
 *
 * @since 2.0
 */
final class UserMapStatus
{
    /** Local record created, Moodle user not yet created/linked. */
    public const PENDING = 'pending';

    /** Linked to an existing Moodle user — active state. */
    public const MAPPED = 'mapped';

    /** Temporarily suspended on the Moodle side. */
    public const SUSPENDED = 'suspended';

    /** Link broken by admin — kept for audit, no longer synced. */
    public const UNLINKED = 'unlinked';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::MAPPED,
            self::SUSPENDED,
            self::UNLINKED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    private function __construct()
    {
    }
}
