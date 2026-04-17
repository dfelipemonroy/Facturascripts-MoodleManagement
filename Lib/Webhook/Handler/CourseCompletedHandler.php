<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler;

use FacturaScripts\Core\DataSrc\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Persists completion_date + grade on the matching MoodleEnrolment
 * row and flips status to `completed` (if the enum supports it) or
 * leaves status alone otherwise. Triggers no certificate issuance
 * directly — that is handled by the existing Fase 5/7 pipeline
 * listening on MoodleEnrolment.Update.
 *
 * Expected payload:
 *   {
 *     "userid":        int,
 *     "courseid":      int,
 *     "timecompleted": int,   // Unix epoch, required
 *     "grade":         float  // optional, 0..100
 *   }
 */
final class CourseCompletedHandler
{
    public static function handle(MoodleInstance $instance, array $payload): void
    {
        $userid = (int) ($payload['userid'] ?? 0);
        $courseid = (int) ($payload['courseid'] ?? 0);
        $timeCompleted = (int) ($payload['timecompleted'] ?? 0);
        if ($userid <= 0 || $courseid <= 0 || $timeCompleted <= 0) {
            return;
        }

        $userMap = new MoodleUserMap();
        $userWhere = [
            new DataBaseWhere('idinstance', (int) $instance->id),
            new DataBaseWhere('moodle_userid', $userid),
        ];
        if (false === $userMap->loadFromCode('', $userWhere)) {
            return;
        }

        $courseMap = new MoodleCourseMap();
        $courseWhere = [
            new DataBaseWhere('idinstance', (int) $instance->id),
            new DataBaseWhere('moodle_courseid', $courseid),
        ];
        if (false === $courseMap->loadFromCode('', $courseWhere)) {
            return;
        }

        $enrolment = new MoodleEnrolment();
        $where = [
            new DataBaseWhere('idcontacto', (int) $userMap->idcontacto),
            new DataBaseWhere('idcourse_map', (int) $courseMap->id),
        ];
        if (false === $enrolment->loadFromCode('', $where)) {
            return;
        }

        if (property_exists($enrolment, 'completion_date')) {
            $enrolment->completion_date = date('Y-m-d H:i:s', $timeCompleted);
        }
        if (property_exists($enrolment, 'final_grade') && isset($payload['grade'])) {
            $enrolment->final_grade = (float) $payload['grade'];
        }
        // Status is NOT modified here — EnrolmentStatus has no
        // "completed" value, and completion_date is the canonical
        // marker consumed by the certificate pipeline (Fase 5/7).
        $enrolment->save();
    }

    private function __construct()
    {
    }
}
