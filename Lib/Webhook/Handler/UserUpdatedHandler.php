<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler;

use FacturaScripts\Core\DataSrc\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Updates cached Moodle-side info on the user map row (username,
 * email, display name) when Moodle reports a profile change. FS
 * remains the source of truth for Contacto; we only refresh the
 * mirror so display screens show the latest Moodle values.
 *
 * Expected payload:
 *   {
 *     "userid":    int,
 *     "username":  string|null,
 *     "email":     string|null,
 *     "firstname": string|null,
 *     "lastname":  string|null
 *   }
 */
final class UserUpdatedHandler
{
    public static function handle(MoodleInstance $instance, array $payload): void
    {
        $userid = (int) ($payload['userid'] ?? 0);
        if ($userid <= 0) {
            return;
        }

        $userMap = new MoodleUserMap();
        $where = [
            new DataBaseWhere('idinstance', (int) $instance->id),
            new DataBaseWhere('moodle_userid', $userid),
        ];
        if (false === $userMap->loadFromCode('', $where)) {
            return;
        }

        if (!empty($payload['username']) && property_exists($userMap, 'moodle_username')) {
            $userMap->moodle_username = (string) $payload['username'];
        }
        if (!empty($payload['email']) && property_exists($userMap, 'moodle_email')) {
            $userMap->moodle_email = (string) $payload['email'];
        }
        if (property_exists($userMap, 'last_sync_at')) {
            $userMap->last_sync_at = date('Y-m-d H:i:s');
        }
        $userMap->save();
    }

    private function __construct()
    {
    }
}
