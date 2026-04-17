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
 * Course WS operations.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1
 */
final class CourseApi
{
    public static function getAll(MoodleInstance $instance, array $courseIds = []): array
    {
        return MoodleClient::getCourses($instance, $courseIds);
    }

    public static function getById(MoodleInstance $instance, int $courseId): ?array
    {
        return MoodleClient::getCourseById($instance, $courseId);
    }

    public static function getByField(MoodleInstance $instance, string $field = '', string $value = ''): array
    {
        return MoodleClient::getCoursesByField($instance, $field, $value);
    }

    public static function getContents(MoodleInstance $instance, int $courseId): array
    {
        return MoodleClient::getCourseContents($instance, $courseId);
    }

    public static function invalidateCache(?int $instanceId = null, ?int $courseId = null): void
    {
        MoodleClient::resetCourseCache($instanceId, $courseId);
    }

    private function __construct()
    {
    }
}
