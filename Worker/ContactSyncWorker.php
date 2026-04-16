<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Worker;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * Propagates changes made to a FacturaScripts Contacto to the mapped
 * Moodle user(s) via `core_user_update_users`.
 *
 * Triggered by `Model.Contacto.Update`. Skips maps whose
 * sync_direction is 'moodle_to_fs' (remote wins policy).
 *
 * Debouncing / coalescing is implemented in Fase 6 F6.8.
 *
 * @since 2.0 PHPDoc completed (existed since 1.0)
 */
class ContactSyncWorker extends WorkerClass
{
    /**
     * @param WorkEvent $event $event->value = Contacto PK (idcontacto).
     * @return bool True once $this->done() is called.
     */
    public function run(WorkEvent $event): bool
    {
        $contact = new Contacto();
        if (false === $contact->load($event->value)) {
            return $this->done();
        }

        $mapModel = new MoodleUserMap();
        $maps = $mapModel->all(
            [
                new DataBaseWhere('idcontacto', $contact->idcontacto),
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
            if ($map->sync_direction === 'moodle_to_fs') {
                continue;
            }

            $instance = $map->getInstance();
            if (empty($instance->id) || $instance->status !== 'active') {
                continue;
            }

            $customFieldsMap = $instance->getCustomFieldsMap();
            $userData = MoodleClient::contactToMoodleUser($contact, $customFieldsMap);
            $result = MoodleClient::updateUser($instance, $map->moodle_userid, $userData);

            if (isset($result['exception'])) {
                $map->last_error = $result['message'] ?? $result['exception'];
                $map->save();
                Tools::log('MoodleManagement')->warning('contact-sync-failed', [
                    '%contact%' => $contact->nombre . ' ' . $contact->apellidos,
                    '%error%' => $map->last_error,
                ]);
                continue;
            }

            $map->last_sync = date('Y-m-d H:i:s');
            $map->last_error = '';
            $map->save();
        }

        return $this->done();
    }
}
