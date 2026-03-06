<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Worker;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleRoleMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class EnrolmentWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        $invoice = new FacturaCliente();
        if (false === $invoice->load($event->value)) {
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

        foreach ($invoice->getLines() as $line) {
            if (empty($line->idproducto)) {
                continue;
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
