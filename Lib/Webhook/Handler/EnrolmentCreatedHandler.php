<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\EnrolmentStatus;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Expected payload:
 *   {
 *     "userid":   int,   // Moodle user id
 *     "courseid": int,   // Moodle course id
 *     "timestart": int,  // optional, Unix epoch
 *     "timeend":   int   // optional, Unix epoch (0 = no limit)
 *   }
 *
 * Mirror the Moodle-originated enrolment into moodle_enrolments so
 * the FS side stays aware of manual enrolments performed directly
 * inside Moodle. Idempotent: existing enrolment rows are updated
 * rather than duplicated.
 */
final class EnrolmentCreatedHandler
{
    public static function handle(MoodleInstance $instance, array $payload): void
    {
        $userid = (int) ($payload['userid'] ?? 0);
        $courseid = (int) ($payload['courseid'] ?? 0);
        if ($userid <= 0 || $courseid <= 0) {
            Tools::log()->warning('mm-webhook-enrol-created-bad-payload', [
                'instance_id' => (int) $instance->id,
            ]);
            return;
        }

        // Resolve FS contact via user map.
        $userMap = new MoodleUserMap();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idinstance', (int) $instance->id),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('moodle_userid', $userid),
        ];
        if (false === $userMap->loadFromCode('', $where)) {
            Tools::log()->notice('mm-webhook-enrol-created-no-usermap', [
                'instance_id' => (int) $instance->id,
                'moodle_userid' => $userid,
            ]);
            return;
        }

        // Resolve FS course via course map.
        $courseMap = new MoodleCourseMap();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idinstance', (int) $instance->id),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('moodle_courseid', $courseid),
        ];
        if (false === $courseMap->loadFromCode('', $where)) {
            Tools::log()->notice('mm-webhook-enrol-created-no-coursemap', [
                'instance_id' => (int) $instance->id,
                'moodle_courseid' => $courseid,
            ]);
            return;
        }

        // Upsert the enrolment record.
        $enrolment = new MoodleEnrolment();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idcontacto', (int) $userMap->idcontacto),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idcourse_map', (int) $courseMap->id),
        ];
        if (false === $enrolment->loadFromCode('', $where)) {
            $enrolment->clear();
            $enrolment->idcontacto = (int) $userMap->idcontacto;
            $enrolment->idcourse_map = (int) $courseMap->id;
        }
        $enrolment->status = EnrolmentStatus::ENROLLED;
        if (!empty($payload['timestart'])) {
            $enrolment->enrolment_date = date('Y-m-d H:i:s', (int) $payload['timestart']);
        }
        if (property_exists($enrolment, 'last_sync_at')) {
            $enrolment->last_sync_at = date('Y-m-d H:i:s');
        }
        $enrolment->save();
    }

    private function __construct()
    {
    }
}
