<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

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

/**
 * Runs the new-user onboarding pipeline when a MoodleUserMap is first
 * created (`Model.MoodleUserMap.Insert` event).
 *
 * Steps, each conditional on the instance configuration:
 *   1. Enrol the user in the configured welcome course.
 *   2. Add the user to the configured onboarding cohort.
 *   3. Send the welcome message over the Moodle messaging API.
 *   4. Create an operator-visible note on the user's profile.
 *
 * All failures are logged and swallowed so one failing step does not
 * prevent the remainder from running.
 *
 * @since 2.0 — F6.5 adds load-retry with exponential backoff so the
 *              worker tolerates the race where the Insert event fires
 *              before the outer transaction commits.
 */
class OnboardingWorker extends WorkerClass
{
    /**
     * How many times we retry loadFromCode() if the MoodleUserMap
     * row is not yet visible (race with an uncommitted transaction
     * that triggered the Insert event).
     *
     * @since 2.0 F6.5
     */
    private const LOAD_RETRIES = 3;

    /** Base delay (ms) between retries; doubles each attempt. */
    private const LOAD_RETRY_BASE_MS = 500;

    /**
     * Entry point called by the WorkQueue when a MoodleUserMap Insert
     * event fires.
     *
     * @param WorkEvent $event Event with $event->value = MoodleUserMap PK.
     * @return bool True once finalised via $this->done() — always true
     *              (errors are logged, not escalated, so the event is
     *              not retried indefinitely).
     */
    public function run(WorkEvent $event): bool
    {
        $map = $this->loadMapWithRetry((int) $event->value);
        if ($map === null) {
            Tools::log('MoodleManagement')->warning('onboarding-map-not-found', [
                'id'       => (int) $event->value,
                'attempts' => self::LOAD_RETRIES,
            ]);
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

    /**
     * F6.5 — load retry with exponential backoff.
     *
     * The FS WorkQueue can fire `Model.MoodleUserMap.Insert` before
     * the outer transaction that inserted the row has committed. In
     * that window, `loadFromCode()` returns false and the onboarding
     * is lost. Up to LOAD_RETRIES attempts with 500 ms / 1 s / 2 s
     * delays cover the slowest observed commit latency.
     *
     * Returns the loaded map or null after all retries are
     * exhausted.
     */
    private function loadMapWithRetry(int $id): ?MoodleUserMap
    {
        $delayMs = self::LOAD_RETRY_BASE_MS;
        for ($attempt = 1; $attempt <= self::LOAD_RETRIES; $attempt++) {
            $map = new MoodleUserMap();
            if ($map->loadFromCode($id)) {
                return $map;
            }
            if ($attempt < self::LOAD_RETRIES) {
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
        return null;
    }

    /**
     * Enrols the user in the instance's welcome course (if configured
     * and not already enrolled).
     *
     * Creates a MoodleEnrolment row with role resolved via
     * MoodleRoleMap::resolveRoleForContact(). Duration is taken from
     * the course map's `duracion_dias` if present; otherwise the
     * enrolment is open-ended.
     *
     * @param MoodleInstance $instance
     * @param MoodleUserMap  $map
     * @return void
     */
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

    /**
     * Adds the user to the instance's onboarding cohort via
     * `core_cohort_add_cohort_members`.
     *
     * @param MoodleInstance $instance
     * @param MoodleUserMap  $map
     * @return void
     */
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

    /**
     * Delivers the welcome message via Moodle's messaging API, with
     * placeholder substitution (%name%, %username%, %site%).
     *
     * Requires $instance->service_userid to be set (the sending user
     * in Moodle). If absent, silently skips.
     *
     * @param MoodleInstance $instance
     * @param MoodleUserMap  $map
     * @return void
     */
    private function sendWelcomeMessage(MoodleInstance $instance, MoodleUserMap $map): void
    {
        if (empty($instance->onboarding_welcome_message) || empty($instance->service_userid)) {
            return;
        }

        $contact = $map->getContacto();

        // F7.12 — respect contact unsubscribe / GDPR marketing opt-out
        // before sending a transactional-ish welcome message. FS core
        // stores the flag as Contacto::$noenviarmail (FS 2024+) or
        // ::$noemail (legacy); we honour either.
        $noEmail = !empty($contact->noenviarmail) || !empty($contact->noemail);
        if ($noEmail) {
            Tools::log('MoodleManagement')->info('onboarding-welcome-skip-noemail', [
                'userid'  => (int) $map->moodle_userid,
                'contact' => (int) ($contact->idcontacto ?? 0),
            ]);
            return;
        }
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

    /**
     * Creates a site-wide visible note on the Moodle user profile with
     * an onboarding timestamp (operator trail).
     *
     * @param MoodleInstance $instance
     * @param MoodleUserMap  $map
     * @return void
     */
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
