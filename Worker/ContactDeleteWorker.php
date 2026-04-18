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
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * Suspends the Moodle user(s) linked to a deleted FacturaScripts
 * Contacto, plus suspends all active enrolments. Does NOT hard-delete
 * the Moodle user — suspension preserves history for audit / fiscal
 * purposes.
 *
 * Triggered by `Model.Contacto.Delete`.
 *
 * @since 2.0 PHPDoc completed (existed since 1.0)
 */
class ContactDeleteWorker extends WorkerClass
{
    /**
     * @param WorkEvent $event $event->value = deleted Contacto PK.
     * @return bool True once $this->done() is called.
     */
    public function run(WorkEvent $event): bool
    {
        $contactId = (int)$event->value;
        if (empty($contactId)) {
            return $this->done();
        }

        $mapModel = new MoodleUserMap();
        $maps = $mapModel->all(
            [
                new DataBaseWhere('idcontacto', $contactId),
                new DataBaseWhere('moodle_userid', 0, '>'),
            ],
            [],
            0,
            0
        );

        if (empty($maps)) {
            return $this->done();
        }

        foreach ($maps as $map) {
            $instance = $map->getInstance();
            if (empty($instance->id) || $instance->status !== 'active') {
                continue;
            }

            // suspend all active enrolments for this user
            $this->suspendEnrolments($contactId, $map->idinstance);

            // suspend the Moodle user account
            $result = MoodleClient::suspendUser($instance, $map->moodle_userid, true);
            if (isset($result['exception'])) {
                Tools::log('MoodleManagement')->warning('contact-delete-suspend-failed', [
                    '%userid%' => $map->moodle_userid,
                    '%error%' => $result['message'] ?? $result['exception'],
                ]);
                continue;
            }

            $map->last_sync = date('Y-m-d H:i:s');
            $map->last_error = '';
            $map->save();

            Tools::log('MoodleManagement')->notice('contact-delete-suspended', [
                '%userid%' => $map->moodle_userid,
                '%instance%' => $instance->name,
            ]);
        }

        return $this->done();
    }

    /**
     * Moves every 'enrolled' MoodleEnrolment for the given contact/
     * instance pair to 'suspended' state via MoodleEnrolment::suspend().
     *
     * @param int $contactId
     * @param int $instanceId
     * @return void
     */
    private function suspendEnrolments(int $contactId, int $instanceId): void
    {
        $enrolModel = new MoodleEnrolment();
        $enrolments = $enrolModel->all(
            [
                new DataBaseWhere('idcontacto', $contactId),
                new DataBaseWhere('idinstance', $instanceId),
                new DataBaseWhere('status', 'enrolled'),
            ],
            [],
            0,
            0
        );

        foreach ($enrolments as $enrolment) {
            $enrolment->suspend();
        }
    }
}
