<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

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
     * Cache-key prefix used to coalesce rapid repeat events for the
     * same contact. When an operator saves a contact 6 times in 5
     * seconds (form submits that auto-save each field) we do NOT
     * want to push 6 full sync cycles through to Moodle.
     *
     * @since 2.0 F6.8 · §2.18
     */
    private const DEDUPE_PREFIX = 'mm:contactsync:recent:';

    /** Debounce window in seconds. */
    private const DEDUPE_WINDOW = 30;

    /**
     * @param WorkEvent $event $event->value = Contacto PK (idcontacto).
     * @return bool True once $this->done() is called.
     */
    public function run(WorkEvent $event): bool
    {
        $contactId = (int) $event->value;
        if ($contactId <= 0) {
            return $this->done();
        }

        // F6.8 — trailing-edge debounce. If we ran for this contact
        // within the last 30 s, the most recent state has already
        // been pushed to Moodle and the queued event is redundant.
        $cacheKey = self::DEDUPE_PREFIX . $contactId;
        if (\FacturaScripts\Core\Tools::cache()->get($cacheKey)) {
            return $this->done();
        }

        $contact = new Contacto();
        if (false === $contact->load($contactId)) {
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

        // F6.8 — stamp the dedupe window on successful completion so
        // subsequent queued events for the same contact skip.
        \FacturaScripts\Core\Tools::cache()->set($cacheKey, true, self::DEDUPE_WINDOW);

        return $this->done();
    }
}
