<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Badge WS operations. Most badge sync logic lives in
 * {@see \FacturaScripts\Plugins\MoodleManagement\Lib\BadgeSyncHelper}.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1
 */
final class BadgeApi
{
    public static function getUserBadges(MoodleInstance $instance, int $userId, int $courseId = 0): array
    {
        return MoodleClient::getUserBadges($instance, $userId, $courseId);
    }

    private function __construct()
    {
    }
}
