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
 * @since 2.0 — Fase 6 F6.1 cut the cascade hazard:
 *   Before: subscribed to `Model.MoodleUserMap.Save`, so the
 *           worker's internal ->save() re-triggered itself
 *           (observed via Cron::userSync → badge sync loop).
 *   After:  subscribed to `Model.MoodleUserMap.Insert` only (first
 *           write). For explicit re-syncs after onboarding, set
 *           MoodleUserMap::$badge_sync_needed = 1 and enqueue via
 *           WorkQueue::add('BadgeSyncWorker', $id). The worker
 *           clears the flag with a private UPDATE statement — no
 *           ->save() — so no event is re-emitted.
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

        // F6.1 — clear badge_sync_needed with a raw UPDATE so we
        // DO NOT trigger Model.MoodleUserMap.Update. A $map->save()
        // here would resurrect the cascade we just cut.
        if (!empty($map->badge_sync_needed)) {
            try {
                $db = new \FacturaScripts\Core\Base\DataBase();
                $db->exec('UPDATE moodle_user_map SET badge_sync_needed = 0 WHERE id = ' . (int) $map->id);
            } catch (\Throwable $e) {
                Tools::log()->warning('badge-sync-flag-clear-failed', [
                    'id'      => (int) $map->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->done();
    }
}
