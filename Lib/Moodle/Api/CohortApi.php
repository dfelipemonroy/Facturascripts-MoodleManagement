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
 * Cohort WS operations (list / members add / members remove).
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1
 */
final class CohortApi
{
    public static function getAll(MoodleInstance $instance): array
    {
        return MoodleClient::getCohorts($instance);
    }

    public static function addMembers(MoodleInstance $instance, int $cohortId, array $moodleUserIds): array
    {
        return MoodleClient::addCohortMembers($instance, $cohortId, $moodleUserIds);
    }

    public static function removeMembers(MoodleInstance $instance, int $cohortId, array $moodleUserIds): array
    {
        return MoodleClient::removeCohortMembers($instance, $cohortId, $moodleUserIds);
    }

    private function __construct()
    {
    }
}
