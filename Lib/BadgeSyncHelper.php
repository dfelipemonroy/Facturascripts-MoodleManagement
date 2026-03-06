<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCertificate;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class BadgeSyncHelper
{
    /**
     * Sync badges for a single Moodle user.
     *
     * @return int Number of badges synced, or -1 on API error.
     */
    public static function syncUserBadges(MoodleInstance $instance, int $moodleUserId, ?int $idcontacto = null): int
    {
        $result = MoodleClient::getUserBadges($instance, $moodleUserId);
        if (isset($result['exception'])) {
            return -1;
        }

        $badges = $result['badges'] ?? [];
        $synced = 0;

        foreach ($badges as $badge) {
            $cert = new MoodleCertificate();
            $where = [
                new DataBaseWhere('idinstance', $instance->id),
                new DataBaseWhere('moodle_userid', $moodleUserId),
                new DataBaseWhere('badge_id', $badge['id']),
            ];

            if (false === $cert->loadFromCode('', $where)) {
                $cert->idinstance = $instance->id;
                $cert->moodle_userid = $moodleUserId;
                $cert->idcontacto = $idcontacto;
                $cert->badge_id = $badge['id'];
            }

            $cert->badge_name = $badge['name'] ?? '';
            $cert->description = $badge['description'] ?? '';
            $cert->moodle_courseid = $badge['courseid'] ?? null;
            $cert->course_name = $badge['coursefullname'] ?? $badge['coursename'] ?? '';
            $cert->date_issued = !empty($badge['dateissued']) ? date('Y-m-d H:i:s', $badge['dateissued']) : null;
            $cert->date_expire = !empty($badge['dateexpire']) ? date('Y-m-d H:i:s', $badge['dateexpire']) : null;
            $cert->unique_hash = $badge['uniquehash'] ?? '';
            $cert->badge_url = $badge['badgeurl'] ?? '';
            $cert->image_url = $badge['imageurl'] ?? $badge['badgeurl'] ?? '';
            $cert->last_sync = date('Y-m-d H:i:s');

            if ($cert->save()) {
                $synced++;
            }
        }

        return $synced;
    }
}
