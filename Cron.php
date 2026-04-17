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

    /**
     * Iterate a FS ModelClass in fixed-size chunks instead of loading
     * the whole result set into memory. The callback receives each
     * row; return false to stop iteration early.
     *
     * Replaces the dangerous `$model->all($where, $order, 0, 0)`
     * pattern flagged by audit §1.6: a client with 50k user_map
     * rows would otherwise load all of them into PHP memory.
     *
     * @param object   $model   Fresh ModelClass instance.
     * @param array    $where   DataBaseWhere/Where clauses.
     * @param array    $orderBy Order-by array (keep stable to avoid
     *                          row-skip when rows mutate mid-scan).
     * @param callable $visitor fn($row):void|bool
     * @return int              Number of rows visited.
     * @since 2.0 F6.3
     */
    protected function paginate(object $model, array $where, array $orderBy, callable $visitor): int
    {
        $offset = 0;
        $visited = 0;
        // Ensure a stable order; default to ascending by PK.
        if (empty($orderBy) && method_exists($model, 'primaryColumn')) {
            $orderBy = [$model::primaryColumn() => 'ASC'];
        }
        while (true) {
            $rows = $model->all($where, $orderBy, $offset, self::BATCH_SIZE);
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $stop = $visitor($row);
                $visited++;
                if ($stop === false) {
                    return $visited;
                }
            }
            if (count($rows) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        }
        return $visited;
    }

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
            $this->userSyncForInstance($instance);
        }
    }

    /**
     * F6.3 — process a single instance's user maps in chunks of
     * self::BATCH_SIZE. Each chunk triggers exactly one Moodle WS
     * call (scoped to the moodle IDs present in the chunk), so
     * memory usage is bounded regardless of total map count.
     */
    private function userSyncForInstance(MoodleInstance $instance): void
    {
        $customFieldsMap = $instance->getCustomFieldsMap();
        $mapModel = new MoodleUserMap();
        $where = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_userid', 0, '>'),
        ];
        $orderBy = ['id' => 'ASC'];
        $offset = 0;

        do {
            $maps = $mapModel->all($where, $orderBy, $offset, self::BATCH_SIZE);
            if (empty($maps)) {
                break;
            }

            $moodleIds = array_map(static function ($m) {
                return $m->moodle_userid;
            }, $maps);

            $result = MoodleClient::getUsersByField($instance, 'id', $moodleIds);
            if (isset($result['exception'])) {
                Tools::log(self::USER_SYNC_JOB)->warning('user-sync-failed', [
                    '%name%'    => $instance->name,
                    '%message%' => $result['message'] ?? $result['exception'],
                ]);
                break;
            }

            $moodleUsers = [];
            foreach ($result as $user) {
                $moodleUsers[$user['id']] = $user;
            }

            foreach ($maps as $map) {
                if (!isset($moodleUsers[$map->moodle_userid])) {
                    continue;
                }
                $this->syncOneUserMap($map, $moodleUsers[$map->moodle_userid], $instance, $customFieldsMap);
            }

            if (count($maps) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        } while (true);
    }

    /**
     * Applies conflict-resolution + persistence for a single map row.
     * Extracted from userSync loop to keep paginate-friendly variants
     * small and testable (Fase 9 will cover with unit tests).
     */
    private function syncOneUserMap(
        MoodleUserMap $map,
        array $moodleUser,
        MoodleInstance $instance,
        array $customFieldsMap
    ): void {
        $moodleModified = $moodleUser['timemodified'] ?? null;
        $lastSyncTs = $map->last_sync ? strtotime($map->last_sync) : 0;
        if ($moodleModified && (int) $moodleModified <= $lastSyncTs && $map->sync_direction === 'moodle_to_fs') {
            return;
        }

        $contact = $map->getContacto();
        if (empty($contact->idcontacto)) {
            return;
        }

        $priority = $map->getEffectivePriority();

        if ($map->sync_direction === 'bidirectional') {
            $winner = MoodleClient::resolveConflict($priority, $contact->fechaalta, $moodleModified);
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
            $this->courseSyncForInstance($instance);
        }
    }

    /**
     * F6.3 — course-map scanner in chunks. Same shape as
     * userSyncForInstance; see its docblock for rationale.
     */
    private function courseSyncForInstance(MoodleInstance $instance): void
    {
        $mapModel = new MoodleCourseMap();
        $where = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_courseid', 0, '>'),
        ];
        $orderBy = ['id' => 'ASC'];
        $offset = 0;

        do {
            $maps = $mapModel->all($where, $orderBy, $offset, self::BATCH_SIZE);
            if (empty($maps)) {
                break;
            }

            $courseIds = array_map(static function ($m) {
                return $m->moodle_courseid;
            }, $maps);

            $result = MoodleClient::getCourses($instance, $courseIds);
            if (isset($result['exception'])) {
                Tools::log(self::COURSE_SYNC_JOB)->warning('course-sync-failed', [
                    '%name%'    => $instance->name,
                    '%message%' => $result['message'] ?? $result['exception'],
                ]);
                break;
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

            if (count($maps) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        } while (true);
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
        // F6.3 — paginate: chunk the local maps and call the WS once
        // per chunk. Missing-user marks happen per chunk boundary.
        $mapModel = new MoodleUserMap();
        $where = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_userid', 0, '>'),
        ];
        $orderBy = ['id' => 'ASC'];
        $offset = 0;

        do {
            $maps = $mapModel->all($where, $orderBy, $offset, self::BATCH_SIZE);
            if (empty($maps)) {
                break;
            }

            $moodleIds = array_map(static function ($m) {
                return $m->moodle_userid;
            }, $maps);
            $result = MoodleClient::getUsersByField($instance, 'id', $moodleIds);
            if (isset($result['exception'])) {
                return;
            }

            $existingIds = [];
            foreach ($result as $user) {
                $existingIds[(int) $user['id']] = true;
            }

            foreach ($maps as $map) {
                if (!isset($existingIds[$map->moodle_userid])) {
                    $map->last_error = Tools::lang()->trans('user-not-found-in-moodle');
                    $map->save();
                    Tools::log(self::RECONCILIATION_JOB)->warning('reconcile-user-missing', [
                        '%userid%'   => $map->moodle_userid,
                        '%instance%' => $instance->name,
                    ]);
                }
            }

            if (count($maps) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        } while (true);
    }

    private function reconcileEnrolments(MoodleInstance $instance): void
    {
        // F6.3 — outer loop paginates course maps; inner loop
        // paginates local enrolments for that course. Each WS call
        // scoped to a single course so payloads stay small.
        $courseMapModel = new MoodleCourseMap();
        $cmWhere = [
            new DataBaseWhere('idinstance', $instance->id),
            new DataBaseWhere('moodle_courseid', 0, '>'),
        ];
        $cmOrder = ['id' => 'ASC'];
        $cmOffset = 0;

        do {
            $courseMaps = $courseMapModel->all($cmWhere, $cmOrder, $cmOffset, self::BATCH_SIZE);
            if (empty($courseMaps)) {
                break;
            }

            foreach ($courseMaps as $courseMap) {
                $enrolledResult = MoodleClient::getEnrolledUsers($instance, $courseMap->moodle_courseid, false);
                if (isset($enrolledResult['exception'])) {
                    continue;
                }

                $moodleEnrolledIds = [];
                foreach ($enrolledResult as $user) {
                    $moodleEnrolledIds[(int) $user['id']] = true;
                }

                $enrolModel = new MoodleEnrolment();
                $enWhere = [
                    new DataBaseWhere('idinstance', $instance->id),
                    new DataBaseWhere('moodle_courseid', $courseMap->moodle_courseid),
                    new DataBaseWhere('status', 'enrolled'),
                ];
                $enOrder = ['id' => 'ASC'];
                $enOffset = 0;
                do {
                    $localEnrolments = $enrolModel->all($enWhere, $enOrder, $enOffset, self::BATCH_SIZE);
                    if (empty($localEnrolments)) {
                        break;
                    }
                    foreach ($localEnrolments as $enrolment) {
                        if (!isset($moodleEnrolledIds[$enrolment->moodle_userid])) {
                            $enrolment->status = 'unenrolled';
                            $enrolment->last_error = Tools::lang()->trans('enrolment-not-found-in-moodle');
                            $enrolment->last_sync = date('Y-m-d H:i:s');
                            $enrolment->save();
                            Tools::log(self::RECONCILIATION_JOB)->warning('reconcile-enrolment-missing', [
                                '%userid%'   => $enrolment->moodle_userid,
                                '%courseid%' => $enrolment->moodle_courseid,
                            ]);
                        }
                    }
                    if (count($localEnrolments) < self::BATCH_SIZE) {
                        break;
                    }
                    $enOffset += self::BATCH_SIZE;
                } while (true);
            }

            if (count($courseMaps) < self::BATCH_SIZE) {
                break;
            }
            $cmOffset += self::BATCH_SIZE;
        } while (true);
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

        // F6.3 — paginate enrolments so a deployment with 100k
        // active enrolments does not load them all into memory.
        $enrolModel = new MoodleEnrolment();
        $where = [
            new DataBaseWhere('status', 'enrolled'),
            new DataBaseWhere('timeend', 0, '>'),
            new DataBaseWhere('timeend', $warningThreshold, '<='),
        ];
        $orderBy = ['id' => 'ASC'];
        $offset = 0;

        do {
            $enrolments = $enrolModel->all($where, $orderBy, $offset, self::BATCH_SIZE);
            if (empty($enrolments)) {
                break;
            }

            foreach ($enrolments as $enrolment) {
                $daysLeft = max(0, (int) ceil(($enrolment->timeend - $now) / 86400));
                if ($enrolment->timeend <= $now) {
                    $enrolment->status = 'unenrolled';
                    $enrolment->notes = Tools::lang()->trans('enrolment-expired');
                    $enrolment->save();
                    Tools::log(self::EXPIRY_CHECK_JOB)->warning('enrolment-expired-auto', [
                        '%userid%'   => $enrolment->moodle_userid,
                        '%courseid%' => $enrolment->moodle_courseid,
                    ]);
                } else {
                    $this->generateRenewalEstimate($enrolment);
                    Tools::log(self::EXPIRY_CHECK_JOB)->info('enrolment-expiring-soon', [
                        '%userid%'   => $enrolment->moodle_userid,
                        '%courseid%' => $enrolment->moodle_courseid,
                        '%days%'     => $daysLeft,
                    ]);
                }
            }

            if (count($enrolments) < self::BATCH_SIZE) {
                break;
            }
            $offset += self::BATCH_SIZE;
        } while (true);
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
