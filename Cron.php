<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\MoodleManagement;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class Cron extends CronClass
{
    const JOB_NAME = 'moodle-health-check';
    const USER_SYNC_JOB = 'moodle-user-sync';
    const COURSE_SYNC_JOB = 'moodle-course-sync';

    public function run(): void
    {
        $job = $this->job(self::JOB_NAME);
        $job->every('1 hour');
        $job->run(function () {
            $this->healthCheck();
        });

        $syncJob = $this->job(self::USER_SYNC_JOB);
        $syncJob->every('6 hours');
        $syncJob->run(function () {
            $this->userSync();
        });

        $courseJob = $this->job(self::COURSE_SYNC_JOB);
        $courseJob->every('6 hours');
        $courseJob->run(function () {
            $this->courseSync();
        });
    }

    private function healthCheck(): void
    {
        $instanceModel = new MoodleInstance();
        $instances = $instanceModel->all(
            [Where::notEq('status', 'inactive'), Where::isNotNull('token')],
            [],
            0,
            0
        );

        foreach ($instances as $instance) {
            $result = MoodleClient::testConnection($instance);

            if (isset($result['exception'])) {
                MoodleClient::applyError($instance, $result);
                $instance->save();
                Tools::log(self::JOB_NAME)->warning('health-check-failed', [
                    '%name%' => $instance->name,
                    '%message%' => $instance->last_error,
                ]);
                continue;
            }

            MoodleClient::applySiteInfo($instance, $result);
            $instance->save();
        }
    }

    private function userSync(): void
    {
        $instanceModel = new MoodleInstance();
        $instances = $instanceModel->all(
            [Where::notEq('status', 'inactive'), Where::isNotNull('token')],
            [],
            0,
            0
        );

        foreach ($instances as $instance) {
            $mapModel = new MoodleUserMap();
            $maps = $mapModel->all(
                [
                    new DataBaseWhere('idinstance', $instance->id),
                    new DataBaseWhere('moodle_userid', 0, '>'),
                ],
                [],
                0,
                0
            );

            if (empty($maps)) {
                continue;
            }

            $customFieldsMap = $instance->getCustomFieldsMap();

            $moodleIds = array_map(function ($m) {
                return $m->moodle_userid;
            }, $maps);

            $result = MoodleClient::getUsersByField($instance, 'id', $moodleIds);

            if (isset($result['exception'])) {
                Tools::log(self::USER_SYNC_JOB)->warning('user-sync-failed', [
                    '%name%' => $instance->name,
                    '%message%' => $result['message'] ?? $result['exception'],
                ]);
                continue;
            }

            $moodleUsers = [];
            foreach ($result as $user) {
                $moodleUsers[$user['id']] = $user;
            }

            foreach ($maps as $map) {
                if (!isset($moodleUsers[$map->moodle_userid])) {
                    continue;
                }

                $moodleUser = $moodleUsers[$map->moodle_userid];

                // Incremental sync: skip if Moodle user hasn't changed since last sync
                $moodleModified = $moodleUser['timemodified'] ?? null;
                $lastSyncTs = $map->last_sync ? strtotime($map->last_sync) : 0;
                if ($moodleModified && (int)$moodleModified <= $lastSyncTs && $map->sync_direction === 'moodle_to_fs') {
                    continue;
                }

                $contact = $map->getContacto();
                if (empty($contact->idcontacto)) {
                    continue;
                }

                // Resolve conflict direction based on priority
                $priority = $map->getEffectivePriority();

                if ($map->sync_direction === 'bidirectional') {
                    $winner = MoodleClient::resolveConflict(
                        $priority,
                        $contact->fechaalta,
                        $moodleModified
                    );

                    if ($winner === 'moodle') {
                        MoodleClient::moodleUserToContact($contact, $moodleUser, $customFieldsMap);
                        $contact->save();
                    } else {
                        $userData = MoodleClient::contactToMoodleUser($contact, $customFieldsMap);
                        MoodleClient::updateUser($instance, $map->moodle_userid, $userData);
                    }
                } elseif ($map->sync_direction === 'moodle_to_fs') {
                    MoodleClient::moodleUserToContact($contact, $moodleUser, $customFieldsMap);
                    $contact->save();
                } elseif ($map->sync_direction === 'fs_to_moodle') {
                    $userData = MoodleClient::contactToMoodleUser($contact, $customFieldsMap);
                    MoodleClient::updateUser($instance, $map->moodle_userid, $userData);
                }

                $map->moodle_username = $moodleUser['username'] ?? $map->moodle_username;
                $map->last_sync = date('Y-m-d H:i:s');
                $map->last_error = '';
                $map->save();
            }
        }
    }

    private function courseSync(): void
    {
        $instanceModel = new MoodleInstance();
        $instances = $instanceModel->all(
            [Where::notEq('status', 'inactive'), Where::isNotNull('token')],
            [],
            0,
            0
        );

        foreach ($instances as $instance) {
            $mapModel = new MoodleCourseMap();
            $maps = $mapModel->all(
                [
                    new DataBaseWhere('idinstance', $instance->id),
                    new DataBaseWhere('moodle_courseid', 0, '>'),
                ],
                [],
                0,
                0
            );

            if (empty($maps)) {
                continue;
            }

            $courseIds = array_map(function ($m) {
                return $m->moodle_courseid;
            }, $maps);

            $result = MoodleClient::getCourses($instance, $courseIds);

            if (isset($result['exception'])) {
                Tools::log(self::COURSE_SYNC_JOB)->warning('course-sync-failed', [
                    '%name%' => $instance->name,
                    '%message%' => $result['message'] ?? $result['exception'],
                ]);
                continue;
            }

            $moodleCourses = [];
            foreach ($result as $course) {
                if (isset($course['id'])) {
                    $moodleCourses[$course['id']] = $course;
                }
            }

            foreach ($maps as $map) {
                if (!isset($moodleCourses[$map->moodle_courseid])) {
                    continue;
                }

                MoodleClient::moodleCourseToMap($map, $moodleCourses[$map->moodle_courseid]);
                $map->last_sync = date('Y-m-d H:i:s');
                $map->last_error = '';
                $map->save();
            }
        }
    }
}
