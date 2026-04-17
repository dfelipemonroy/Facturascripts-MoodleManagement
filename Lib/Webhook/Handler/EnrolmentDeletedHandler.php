<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler;

use FacturaScripts\Core\DataSrc\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\EnrolmentStatus;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Marks the FS enrolment as cancelled when Moodle fires an
 * enrol-removed event. We never physically delete the row — keeping
 * the audit trail (with status=cancelled) is mandatory for fiscal
 * reasons (the invoice that paid for it still exists).
 *
 * Expected payload:
 *   { "userid": int, "courseid": int }
 */
final class EnrolmentDeletedHandler
{
    public static function handle(MoodleInstance $instance, array $payload): void
    {
        $userid = (int) ($payload['userid'] ?? 0);
        $courseid = (int) ($payload['courseid'] ?? 0);
        if ($userid <= 0 || $courseid <= 0) {
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
            Tools::log()->notice('mm-webhook-enrol-deleted-not-found', [
                'instance_id' => (int) $instance->id,
            ]);
            return;
        }
        $enrolment->status = EnrolmentStatus::CANCELLED;
        if (property_exists($enrolment, 'last_sync_at')) {
            $enrolment->last_sync_at = date('Y-m-d H:i:s');
        }
        $enrolment->save();
    }

    private function __construct()
    {
    }
}
