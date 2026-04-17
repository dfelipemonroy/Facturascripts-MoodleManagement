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
 * Enrolment WS operations (manual + self + meta).
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1
 */
final class EnrolmentApi
{
    public static function enrol(MoodleInstance $instance, array $enrolments): array
    {
        return MoodleClient::enrolUsers($instance, $enrolments);
    }

    public static function unenrol(MoodleInstance $instance, array $enrolments): array
    {
        return MoodleClient::unenrolUsers($instance, $enrolments);
    }

    public static function getEnrolled(MoodleInstance $instance, int $courseId, bool $includeHidden = false): array
    {
        return MoodleClient::getEnrolledUsers($instance, $courseId, $includeHidden);
    }

    public static function getMethods(MoodleInstance $instance, int $courseId): array
    {
        return MoodleClient::getCourseEnrolmentMethods($instance, $courseId);
    }

    private function __construct()
    {
    }
}
