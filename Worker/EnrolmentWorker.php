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
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Lib\WorkQueue\IdempotencyGuard;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleRoleMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * Central enrolment reconciliation worker.
 *
 * Triggered by `Model.FacturaCliente.Update`:
 *   - If the invoice is `pagada = true`: iterates lines whose product
 *     is mapped to a Moodle course and calls
 *     MoodleEnrolment::enrol() to register them in Moodle.
 *   - If the invoice is NOT paid: any pending enrolment linked to
 *     that invoice is suspended / rolled back.
 *
 * Fase 7 F7.9 adds cantidad (line quantity) multiplication so that
 * pack-style products enrol N slots per line.
 *
 * @since 2.0 PHPDoc completed (existed since 1.0)
 */
class EnrolmentWorker extends WorkerClass
{
    /**
     * Maximum retries when a transient Moodle API error occurs
     * (network timeout, 5xx, token rotation in flight).
     * Consumed by Fase 6 F6.7 (RetryPolicy).
     *
     * @since 2.0
     */
    public const MAX_RETRIES = 3;

    /**
     * Base delay (milliseconds) for exponential backoff between
     * retries. Real delay is BASE * 2^attempt.
     *
     * @since 2.0
     */
    public const RETRY_BASE_DELAY_MS = 500;

    /**
     * @param WorkEvent $event $event->value = FacturaCliente PK.
     * @return bool True once $this->done() is called.
     */
    public function run(WorkEvent $event): bool
    {
        $invoice = new FacturaCliente();
        if (false === $invoice->load($event->value)) {
            return $this->done();
        }

        // BE-03 (2026-04-17) — idempotency gate. FS model events fire
        // twice in some cascade paths (save + pre-commit), and the
        // WorkQueue may redeliver the same event on transient retry.
        // Without this gate a paid invoice could surface two enrol
        // calls for the same (user, course) pair, leaving the second
        // in a broken state whenever Moodle rejected the duplicate.
        //
        // Key includes the paid flag so a state transition
        // (pagada=0 → pagada=1) is treated as a fresh job and does
        // not collide with the earlier "unenrol" pass.
        $idemKey = sprintf(
            'enrol:invoice=%d:pagada=%d',
            (int) $invoice->idfactura,
            $invoice->pagada ? 1 : 0
        );
        if (!IdempotencyGuard::beginOnce($idemKey, 3600)) {
            Tools::log('MoodleManagement')->info('enrol-skipped-duplicate', [
                'invoice' => (int) $invoice->idfactura,
                'pagada'  => $invoice->pagada ? 1 : 0,
            ]);
            return $this->done();
        }

        if ($invoice->pagada) {
            $this->processEnrolments($invoice);
        } else {
            $this->processUnenrolments($invoice);
        }

        return $this->done();
    }

    private function processEnrolments(FacturaCliente $invoice): void
    {
        $contactId = $this->getContactId($invoice);
        if (empty($contactId)) {
            return;
        }

        // F6.6 — wrap the enrolment loop in a single transaction so a
        // network failure halfway through a multi-line invoice does
        // not leave half the enrolments persisted (with Moodle-side
        // state) and the other half unrecorded locally. If any line
        // throws, everything rolls back and the WorkQueue retries on
        // the next FacturaCliente.Update event.
        $db = new \FacturaScripts\Core\Base\DataBase();
        $db->beginTransaction();
        try {
            foreach ($invoice->getLines() as $line) {
                if (empty($line->idproducto)) {
                    continue;
                }

                // F7.9 — one invoice line = one (user, course) Moodle
                // enrolment. Moodle disallows the same user being
                // enrolled twice in the same course, so `cantidad > 1`
                // on a single line maps to a single seat for the
                // billing contact. Pack-style multi-seat products
                // must ship one line per seat (documented in README
                // "Known limitations"). We log a warning when this
                // assumption is violated so operators spot configs
                // that expect seat multiplication.
                if (!empty($line->cantidad) && (float) $line->cantidad > 1) {
                    Tools::log('MoodleManagement')->info('enrol-cantidad-gt-1', [
                        'invoice' => (int) $invoice->idfactura,
                        'line'    => (int) ($line->idlinea ?? 0),
                        'qty'     => (float) $line->cantidad,
                    ]);
                }

                $courseMap = new MoodleCourseMap();
                $cmWhere = [new DataBaseWhere('idproducto', $line->idproducto)];
                if (false === $courseMap->loadFromCode('', $cmWhere)) {
                    continue;
                }

                $userMap = new MoodleUserMap();
                $umWhere = [
                    new DataBaseWhere('idcontacto', $contactId),
                    new DataBaseWhere('idinstance', $courseMap->idinstance),
                ];
                if (false === $userMap->loadFromCode('', $umWhere)) {
                    Tools::log('MoodleManagement')->warning('no-user-map', [
                        '%contact%' => $contactId,
                    ]);
                    continue;
                }

                $this->enrolUser($courseMap, $userMap, $invoice, $contactId);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            Tools::log('MoodleManagement')->error('enrolment-transaction-failed', [
                'invoice'   => (int) $invoice->idfactura,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function enrolUser(MoodleCourseMap $courseMap, MoodleUserMap $userMap, FacturaCliente $invoice, int $contactId): void
    {
        $enrolment = new MoodleEnrolment();
        $where = [
            new DataBaseWhere('idinstance', $courseMap->idinstance),
            new DataBaseWhere('moodle_userid', $userMap->moodle_userid),
            new DataBaseWhere('moodle_courseid', $courseMap->moodle_courseid),
        ];

        if (false === $enrolment->loadFromCode('', $where)) {
            $enrolment->idinstance = $courseMap->idinstance;
            $enrolment->idcontacto = $contactId;
            $enrolment->moodle_userid = $userMap->moodle_userid;
            $enrolment->moodle_courseid = $courseMap->moodle_courseid;
            $enrolment->idcourse_map = $courseMap->id;
            $enrolment->enrolment_method = 'manual';
            $enrolment->roleid = MoodleRoleMap::resolveRoleForContact($courseMap->idinstance, $contactId);
        }

        $enrolment->idfactura = $invoice->idfactura;
        $enrolment->notes = Tools::lang()->trans('auto-enrol-on-payment');

        // set timestart/timeend based on course duration
        if (empty($enrolment->timestart)) {
            $enrolment->timestart = time();
        }
        if (!empty($courseMap->duracion_dias) && $courseMap->duracion_dias > 0) {
            $enrolment->timeend = $enrolment->timestart + ($courseMap->duracion_dias * 86400);
        }

        // check if course has self-enrolment and store the key
        $this->detectSelfEnrolmentKey($enrolment, $courseMap);

        if ($enrolment->enrol()) {
            Tools::log('MoodleManagement')->notice('enrol-success');
        } else {
            Tools::log('MoodleManagement')->error('enrol-failed', [
                '%error%' => $enrolment->last_error,
            ]);
        }
    }

    private function detectSelfEnrolmentKey(MoodleEnrolment $enrolment, MoodleCourseMap $courseMap): void
    {
        $instance = $courseMap->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            return;
        }

        $methods = MoodleClient::getCourseEnrolmentMethods($instance, $courseMap->moodle_courseid);
        if (isset($methods['exception']) || !is_array($methods)) {
            return;
        }

        foreach ($methods as $method) {
            if (($method['type'] ?? '') !== 'self' || ($method['status'] ?? 1) != 0) {
                continue;
            }

            $instanceId = $method['id'] ?? 0;
            if (empty($instanceId)) {
                continue;
            }

            $info = MoodleClient::getSelfEnrolmentInfo($instance, $instanceId);
            if (isset($info['exception'])) {
                continue;
            }

            if (!empty($info['enrolmentinfo']['enrolpassword'] ?? '')) {
                $enrolment->enrolment_key = $info['enrolmentinfo']['enrolpassword'];
            }
            $enrolment->enrolment_method = 'self';
            break;
        }
    }

    private function processUnenrolments(FacturaCliente $invoice): void
    {
        $enrolment = new MoodleEnrolment();
        $where = [new DataBaseWhere('idfactura', $invoice->idfactura)];
        foreach ($enrolment->all($where) as $record) {
            if (false === $record->isActive()) {
                continue;
            }

            if ($record->unenrol()) {
                Tools::log('MoodleManagement')->notice('unenrol-success');
            } else {
                Tools::log('MoodleManagement')->error('unenrol-failed', [
                    '%error%' => $record->last_error,
                ]);
            }
        }
    }

    private function getContactId(FacturaCliente $invoice): int
    {
        if (!empty($invoice->idcontactofact)) {
            return (int)$invoice->idcontactofact;
        }

        $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
        if ($cliente->load($invoice->codcliente) && !empty($cliente->idcontactofact)) {
            return (int)$cliente->idcontactofact;
        }

        return 0;
    }
}
