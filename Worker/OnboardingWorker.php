<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Worker;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleRoleMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class OnboardingWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $map = new MoodleUserMap();
        if (false === $map->loadFromCode($event->value)) {
            return $this->done();
        }

        if (empty($map->moodle_userid)) {
            return $this->done();
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            return $this->done();
        }

        if (empty($instance->onboarding_enabled)) {
            return $this->done();
        }

        $this->enrolInWelcomeCourse($instance, $map);
        $this->addToCohort($instance, $map);
        $this->sendWelcomeMessage($instance, $map);
        $this->createOnboardingNote($instance, $map);

        return $this->done();
    }

    private function enrolInWelcomeCourse(MoodleInstance $instance, MoodleUserMap $map): void
    {
        if (empty($instance->onboarding_course_id)) {
            return;
        }

        // check if already enrolled
        $enrolment = new MoodleEnrolment();
        $where = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_userid', $map->moodle_userid),
            new DataBaseWhere('moodle_courseid', $instance->onboarding_course_id),
        ];
        if ($enrolment->loadFromCode('', $where)) {
            return;
        }

        // find the course map for duration info
        $courseMap = new MoodleCourseMap();
        $cmWhere = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_courseid', $instance->onboarding_course_id),
        ];
        $courseMap->loadFromCode('', $cmWhere);

        $enrolment->idinstance = $instance->id;
        $enrolment->idcontacto = $map->idcontacto;
        $enrolment->moodle_userid = $map->moodle_userid;
        $enrolment->moodle_courseid = $instance->onboarding_course_id;
        $enrolment->idcourse_map = $courseMap->id ?? null;
        $enrolment->enrolment_method = 'manual';
        $enrolment->roleid = MoodleRoleMap::resolveRoleForContact($instance->id, $map->idcontacto);
        $enrolment->notes = Tools::lang()->trans('onboarding-auto-enrol');
        $enrolment->timestart = time();

        if (!empty($courseMap->duracion_dias) && $courseMap->duracion_dias > 0) {
            $enrolment->timeend = $enrolment->timestart + ($courseMap->duracion_dias * 86400);
        }

        if ($enrolment->enrol()) {
            Tools::log('MoodleManagement')->notice('onboarding-enrol-success', [
                '%userid%' => $map->moodle_userid,
                '%courseid%' => $instance->onboarding_course_id,
            ]);
        } else {
            Tools::log('MoodleManagement')->warning('onboarding-enrol-failed', [
                '%userid%' => $map->moodle_userid,
                '%error%' => $enrolment->last_error,
            ]);
        }
    }

    private function addToCohort(MoodleInstance $instance, MoodleUserMap $map): void
    {
        if (empty($instance->onboarding_cohort_id)) {
            return;
        }

        $result = MoodleClient::addCohortMembers(
            $instance,
            $instance->onboarding_cohort_id,
            [$map->moodle_userid]
        );

        if (isset($result['exception'])) {
            Tools::log('MoodleManagement')->warning('onboarding-cohort-failed', [
                '%userid%' => $map->moodle_userid,
                '%error%' => $result['message'] ?? $result['exception'],
            ]);
        } else {
            Tools::log('MoodleManagement')->notice('onboarding-cohort-success', [
                '%userid%' => $map->moodle_userid,
                '%cohortid%' => $instance->onboarding_cohort_id,
            ]);
        }
    }

    private function sendWelcomeMessage(MoodleInstance $instance, MoodleUserMap $map): void
    {
        if (empty($instance->onboarding_welcome_message) || empty($instance->service_userid)) {
            return;
        }

        $contact = $map->getContacto();
        $text = str_replace(
            ['%name%', '%username%', '%site%'],
            [
                trim(($contact->nombre ?? '') . ' ' . ($contact->apellidos ?? '')),
                $map->moodle_username ?? '',
                $instance->site_name ?? $instance->name,
            ],
            $instance->onboarding_welcome_message
        );

        $result = MoodleClient::sendInstantMessages($instance, [[
            'touserid' => $map->moodle_userid,
            'text' => $text,
        ]]);

        if (isset($result['exception']) || (isset($result[0]['errormessage']) && !empty($result[0]['errormessage']))) {
            Tools::log('MoodleManagement')->warning('onboarding-message-failed', [
                '%userid%' => $map->moodle_userid,
                '%error%' => $result['message'] ?? $result[0]['errormessage'] ?? $result['exception'] ?? '',
            ]);
        } else {
            Tools::log('MoodleManagement')->notice('onboarding-message-sent', [
                '%userid%' => $map->moodle_userid,
            ]);
        }
    }

    private function createOnboardingNote(MoodleInstance $instance, MoodleUserMap $map): void
    {
        $contact = $map->getContacto();
        $noteText = Tools::lang()->trans('onboarding-note-text', [
            '%name%' => trim(($contact->nombre ?? '') . ' ' . ($contact->apellidos ?? '')),
            '%date%' => date('Y-m-d H:i'),
        ]);

        $result = MoodleClient::createNotes($instance, [[
            'userid' => $map->moodle_userid,
            'courseid' => 1,
            'publishstate' => 'site',
            'text' => $noteText,
        ]]);

        if (isset($result['exception'])) {
            Tools::log('MoodleManagement')->warning('onboarding-note-failed', [
                '%userid%' => $map->moodle_userid,
                '%error%' => $result['message'] ?? $result['exception'],
            ]);
        }
    }
}
