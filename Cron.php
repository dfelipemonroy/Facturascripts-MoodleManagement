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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class Cron extends CronClass
{
    // Job names ------------------------------------------------------
    public const JOB_NAME = 'moodle-health-check';
    public const USER_SYNC_JOB = 'moodle-user-sync';
    public const COURSE_SYNC_JOB = 'moodle-course-sync';
    public const RECONCILIATION_JOB = 'moodle-reconciliation';
    public const CLEANUP_JOB = 'moodle-cleanup';
    public const EXPIRY_CHECK_JOB = 'moodle-expiry-check';

    // Schedule intervals (consumed by $job->every()) -----------------
    /** @since 2.0 */
    public const EVERY_HOUR = '1 hour';
    /** @since 2.0 */
    public const EVERY_6_HOURS = '6 hours';
    /** @since 2.0 */
    public const EVERY_DAY = '1 day';

    // Pagination -----------------------------------------------------
    /**
     * Batch size for paginated cron scans (applied in Fase 6 F6.3).
     * Keeps memory bounded when iterating tables with 10k+ rows.
     *
     * @since 2.0
     */
    public const BATCH_SIZE = 500;

    public function run(): void
    {
        $job = $this->job(self::JOB_NAME);
        $job->every(self::EVERY_HOUR);
        $job->run(function () {
            $this->healthCheck();
        });

        $syncJob = $this->job(self::USER_SYNC_JOB);
        $syncJob->every(self::EVERY_6_HOURS);
        $syncJob->run(function () {
            $this->userSync();
        });

        $courseJob = $this->job(self::COURSE_SYNC_JOB);
        $courseJob->every(self::EVERY_6_HOURS);
        $courseJob->run(function () {
            $this->courseSync();
        });

        $reconJob = $this->job(self::RECONCILIATION_JOB);
        $reconJob->every(self::EVERY_DAY);
        $reconJob->run(function () {
            $this->reconciliation();
        });

        $cleanupJob = $this->job(self::CLEANUP_JOB);
        $cleanupJob->every(self::EVERY_DAY);
        $cleanupJob->run(function () {
            $this->cleanup();
        });

        $expiryJob = $this->job(self::EXPIRY_CHECK_JOB);
        $expiryJob->every(self::EVERY_6_HOURS);
        $expiryJob->run(function () {
            $this->expiryCheck();
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

    private function reconciliation(): void
    {
        $instanceModel = new MoodleInstance();
        $instances = $instanceModel->all(
            [Where::notEq('status', 'inactive'), Where::isNotNull('token')],
            [],
            0,
            0
        );

        foreach ($instances as $instance) {
            $this->reconcileUsers($instance);
            $this->reconcileEnrolments($instance);
        }
    }

    private function reconcileUsers(MoodleInstance $instance): void
    {
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
            return;
        }

        $moodleIds = array_map(function ($m) {
            return $m->moodle_userid;
        }, $maps);

        $result = MoodleClient::getUsersByField($instance, 'id', $moodleIds);
        if (isset($result['exception'])) {
            return;
        }

        $existingIds = [];
        foreach ($result as $user) {
            $existingIds[(int)$user['id']] = true;
        }

        foreach ($maps as $map) {
            if (!isset($existingIds[$map->moodle_userid])) {
                $map->last_error = Tools::lang()->trans('user-not-found-in-moodle');
                $map->save();
                Tools::log(self::RECONCILIATION_JOB)->warning('reconcile-user-missing', [
                    '%userid%' => $map->moodle_userid,
                    '%instance%' => $instance->name,
                ]);
            }
        }
    }

    private function reconcileEnrolments(MoodleInstance $instance): void
    {
        $courseMapModel = new MoodleCourseMap();
        $courseMaps = $courseMapModel->all(
            [
                new DataBaseWhere('idinstance', $instance->id),
                new DataBaseWhere('moodle_courseid', 0, '>'),
            ],
            [],
            0,
            0
        );

        foreach ($courseMaps as $courseMap) {
            $enrolledResult = MoodleClient::getEnrolledUsers($instance, $courseMap->moodle_courseid, false);
            if (isset($enrolledResult['exception'])) {
                continue;
            }

            $moodleEnrolledIds = [];
            foreach ($enrolledResult as $user) {
                $moodleEnrolledIds[(int)$user['id']] = true;
            }

            $enrolModel = new MoodleEnrolment();
            $localEnrolments = $enrolModel->all(
                [
                    new DataBaseWhere('idinstance', $instance->id),
                    new DataBaseWhere('moodle_courseid', $courseMap->moodle_courseid),
                    new DataBaseWhere('status', 'enrolled'),
                ],
                [],
                0,
                0
            );

            foreach ($localEnrolments as $enrolment) {
                if (!isset($moodleEnrolledIds[$enrolment->moodle_userid])) {
                    $enrolment->status = 'unenrolled';
                    $enrolment->last_error = Tools::lang()->trans('enrolment-not-found-in-moodle');
                    $enrolment->last_sync = date('Y-m-d H:i:s');
                    $enrolment->save();
                    Tools::log(self::RECONCILIATION_JOB)->warning('reconcile-enrolment-missing', [
                        '%userid%' => $enrolment->moodle_userid,
                        '%courseid%' => $enrolment->moodle_courseid,
                    ]);
                }
            }
        }
    }

    private function cleanup(): void
    {
        $this->cleanOrphanedUserMaps();
        $this->cleanOrphanedEnrolments();
    }

    private function cleanOrphanedUserMaps(): void
    {
        $db = new DataBase();
        $sql = "SELECT m.id FROM moodle_user_map m"
            . " LEFT JOIN contactos c ON m.idcontacto = c.idcontacto"
            . " WHERE c.idcontacto IS NULL";

        $rows = $db->select($sql);
        if (empty($rows)) {
            return;
        }

        $count = 0;
        $mapModel = new MoodleUserMap();
        foreach ($rows as $row) {
            $map = new MoodleUserMap();
            if ($map->loadFromCode($row['id'])) {
                $map->delete();
                $count++;
            }
        }

        if ($count > 0) {
            Tools::log(self::CLEANUP_JOB)->notice('cleanup-orphaned-user-maps', [
                '%count%' => $count,
            ]);
        }
    }

    private function cleanOrphanedEnrolments(): void
    {
        $db = new DataBase();
        $sql = "SELECT e.id FROM moodle_enrolments e"
            . " LEFT JOIN contactos c ON e.idcontacto = c.idcontacto"
            . " WHERE c.idcontacto IS NULL";

        $rows = $db->select($sql);
        if (empty($rows)) {
            return;
        }

        $count = 0;
        foreach ($rows as $row) {
            $enrolment = new MoodleEnrolment();
            if ($enrolment->loadFromCode($row['id'])) {
                $enrolment->delete();
                $count++;
            }
        }

        if ($count > 0) {
            Tools::log(self::CLEANUP_JOB)->notice('cleanup-orphaned-enrolments', [
                '%count%' => $count,
            ]);
        }
    }

    private function expiryCheck(): void
    {
        $warningDays = 7;
        $now = time();
        $warningThreshold = $now + ($warningDays * 86400);

        $enrolModel = new MoodleEnrolment();
        $enrolments = $enrolModel->all(
            [
                new DataBaseWhere('status', 'enrolled'),
                new DataBaseWhere('timeend', 0, '>'),
                new DataBaseWhere('timeend', $warningThreshold, '<='),
            ],
            [],
            0,
            0
        );

        foreach ($enrolments as $enrolment) {
            $daysLeft = max(0, (int)ceil(($enrolment->timeend - $now) / 86400));

            if ($enrolment->timeend <= $now) {
                $enrolment->status = 'unenrolled';
                $enrolment->notes = Tools::lang()->trans('enrolment-expired');
                $enrolment->save();
                Tools::log(self::EXPIRY_CHECK_JOB)->warning('enrolment-expired-auto', [
                    '%userid%' => $enrolment->moodle_userid,
                    '%courseid%' => $enrolment->moodle_courseid,
                ]);
            } else {
                // generate renewal estimate if not already created
                $this->generateRenewalEstimate($enrolment);

                Tools::log(self::EXPIRY_CHECK_JOB)->info('enrolment-expiring-soon', [
                    '%userid%' => $enrolment->moodle_userid,
                    '%courseid%' => $enrolment->moodle_courseid,
                    '%days%' => $daysLeft,
                ]);
            }
        }
    }

    /**
     * Cache-key prefix used to signal PreEnrolmentWorker that a
     * given PresupuestoCliente id was auto-created by this cron and
     * does NOT need to be scanned for pending enrolments — the
     * matching MoodleEnrolment already exists with idpresupuesto
     * pointing at it.
     *
     * Without this guard the PresupuestoCliente save() issued below
     * would bounce back to PreEnrolmentWorker (Cron.php §1.2 per
     * audit), which would then try to seed pending enrolments and
     * potentially re-emit further events.
     *
     * @since 2.0 F6.2 · §1.2
     */
    private const SKIP_PREENROL_PREFIX = 'mm:pre-enrol-skip:presupuesto:';

    private function generateRenewalEstimate(MoodleEnrolment $enrolment): void
    {
        // skip if a renewal estimate already exists for this enrolment
        if (!empty($enrolment->idpresupuesto)) {
            return;
        }

        $courseMap = $enrolment->getCourseMap();
        if (null === $courseMap || empty($courseMap->duracion_dias) || empty($courseMap->idproducto)) {
            return;
        }

        $contacto = $enrolment->getContacto();
        if (empty($contacto->idcontacto)) {
            return;
        }

        // find the client linked to this contact
        $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
        if (!empty($contacto->codcliente)) {
            $cliente->loadFromCode($contacto->codcliente);
        }

        if (empty($cliente->codcliente)) {
            return;
        }

        $presupuesto = new PresupuestoCliente();
        $presupuesto->setSubject($cliente);
        $presupuesto->idcontactofact = $contacto->idcontacto;
        $presupuesto->observaciones = Tools::lang()->trans('renewal-estimate-note', [
            '%course%' => $courseMap->fullname,
        ]);

        if (false === $presupuesto->save()) {
            Tools::log(self::EXPIRY_CHECK_JOB)->warning('renewal-estimate-failed', [
                '%userid%' => $enrolment->moodle_userid,
                '%courseid%' => $enrolment->moodle_courseid,
            ]);
            return;
        }

        // F6.2 — tag the just-saved estimate so PreEnrolmentWorker
        // skips it. TTL is intentionally generous (300 s) to cover
        // queue back-pressure; the worker deletes the key on read.
        Tools::cache()->set(
            self::SKIP_PREENROL_PREFIX . (int) $presupuesto->idpresupuesto,
            true,
            300
        );

        // add the course product line
        $producto = $courseMap->getProducto();
        $newLine = $presupuesto->getNewLine();
        $newLine->referencia = $producto->referencia ?? '';
        $newLine->descripcion = $courseMap->fullname;
        $newLine->cantidad = 1;
        $newLine->pvpunitario = $courseMap->price;
        $newLine->save();

        // link the estimate to the enrolment
        $enrolment->idpresupuesto = $presupuesto->idpresupuesto;
        $enrolment->save();

        Tools::log(self::EXPIRY_CHECK_JOB)->notice('renewal-estimate-created', [
            '%course%' => $courseMap->fullname,
            '%client%' => $cliente->nombre,
        ]);
    }
}
