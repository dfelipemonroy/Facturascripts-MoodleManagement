<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Worker;

use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\BadgeSyncHelper;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * Pulls earned badges from Moodle for a given user mapping and
 * mirrors them in the plugin's local storage.
 *
 * WARNING: currently bound to `Model.MoodleUserMap.Save` which is a
 * **cascade risk** — if the worker itself triggers ->save() on the
 * map, the event re-fires. Fase 6 F6.1 rebinds this to
 * `Model.MoodleUserMap.Insert` and introduces an explicit
 * `badge_sync_needed` flag for subsequent re-syncs.
 *
 * @since 2.0 PHPDoc completed (existed since 1.0)
 */
class BadgeSyncWorker extends WorkerClass
{
    /**
     * @param WorkEvent $event $event->value = MoodleUserMap PK.
     * @return bool True once $this->done() is called.
     */
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

        $synced = BadgeSyncHelper::syncUserBadges($instance, $map->moodle_userid, $map->idcontacto);
        if ($synced < 0) {
            Tools::log('MoodleManagement')->warning('sync-failed', [
                '%message%' => 'Badge sync API error for user ' . $map->moodle_userid,
            ]);
        }

        return $this->done();
    }
}
