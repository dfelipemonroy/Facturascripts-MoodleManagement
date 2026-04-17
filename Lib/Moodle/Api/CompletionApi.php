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
 * @since 2.0 — V2.0-ACTION-PLAN F10.2 · §6.15
 *
 * Thin facade over completion-related Moodle web services. Groups
 * the per-user, per-course completion calls that the progress-sync
 * cron drives, so callers don't have to know the raw WS function
 * names and MoodleClient can be refactored without breaking them.
 */
final class CompletionApi
{
    /**
     * Activities-level completion. Returns:
     *   ['statuses' => [ {cmid, modname, state, timecompleted, tracking}, … ]]
     * Failures include an 'exception' key.
     */
    public static function getActivities(MoodleInstance $instance, int $courseId, int $userId): array
    {
        return MoodleClient::getActivitiesCompletionStatus($instance, $courseId, $userId);
    }

    /**
     * Course-level completion. Includes a completionstatus.completed
     * boolean when Moodle course completion tracking is on.
     */
    public static function getCourse(MoodleInstance $instance, int $courseId, int $userId): array
    {
        return MoodleClient::getCourseCompletionStatus($instance, $courseId, $userId);
    }

    /**
     * Grade items — used to populate moodle_enrolments.final_grade
     * when the course grade tree exposes a course-total item.
     */
    public static function getGradeItems(MoodleInstance $instance, int $userId, int $courseId = 0): array
    {
        return MoodleClient::getUserGradeItems($instance, $userId, $courseId);
    }

    /**
     * Distil {statuses:[...]} into a compact progress record suitable
     * for persisting in moodle_enrolments. Returns null when the WS
     * response is an error or shaped unexpectedly.
     *
     * Shape:
     *   [
     *     'completed' => int,
     *     'total'     => int,
     *     'percent'   => int (0..100),
     *     'lastActivityAt' => int|null (Unix epoch of most recent
     *                                   timecompleted, null if none)
     *   ]
     */
    public static function summariseActivities(array $wsResponse): ?array
    {
        if (isset($wsResponse['exception'])) {
            return null;
        }
        $statuses = $wsResponse['statuses'] ?? null;
        if (!is_array($statuses)) {
            return null;
        }
        $total = count($statuses);
        $completed = 0;
        $lastActivity = null;
        foreach ($statuses as $s) {
            if (!is_array($s)) {
                continue;
            }
            $state = (int) ($s['state'] ?? 0);
            if ($state > 0) {
                $completed++;
            }
            $ts = (int) ($s['timecompleted'] ?? 0);
            if ($ts > 0 && ($lastActivity === null || $ts > $lastActivity)) {
                $lastActivity = $ts;
            }
        }
        $percent = $total > 0 ? (int) floor(($completed / $total) * 100) : 0;
        return [
            'completed'       => $completed,
            'total'           => $total,
            'percent'         => max(0, min(100, $percent)),
            'lastActivityAt'  => $lastActivity,
        ];
    }

    private function __construct()
    {
    }
}
