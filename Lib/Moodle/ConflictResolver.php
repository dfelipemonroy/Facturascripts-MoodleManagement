<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

/**
 * Namespaced facade over MoodleClient::resolveConflict for the
 * MoodleManagement v2.0 split. See {@see MoodleClient::resolveConflict()}
 * for the full decision table.
 *
 * Accepted priority values: `fs_wins`, `moodle_wins`, `newest_wins`.
 * Returns `'fs'`, `'moodle'`, or `'conflict'`.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1 split · §1.4
 */
final class ConflictResolver
{
    public const PRIORITY_FS_WINS = 'fs_wins';
    public const PRIORITY_MOODLE_WINS = 'moodle_wins';
    public const PRIORITY_NEWEST_WINS = 'newest_wins';

    public const WINNER_FS = 'fs';
    public const WINNER_MOODLE = 'moodle';
    public const WINNER_CONFLICT = 'conflict';

    public static function decide(string $priority, ?string $fsModified, ?string $moodleModified): string
    {
        return MoodleClient::resolveConflict($priority, $fsModified, $moodleModified);
    }

    private function __construct()
    {
    }
}
