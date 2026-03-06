<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 *
 * Creates pending enrolment records when a Presupuesto or Pedido is saved.
 * No Moodle API call is made — enrolments stay as 'pending' until the
 * corresponding invoice is marked as paid (handled by EnrolmentWorker).
 */

namespace FacturaScripts\Plugins\MoodleManagement\Worker;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\WorkEvent;
use FacturaScripts\Core\Template\WorkerClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\PedidoCliente;
use FacturaScripts\Dinamic\Model\PresupuestoCliente;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleRoleMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class PreEnrolmentWorker extends WorkerClass
{
    public function run(WorkEvent $event): bool
    {
        if ($event->name === 'Model.PresupuestoCliente.Update') {
            $doc = new PresupuestoCliente();
            $docType = 'presupuesto';
        } elseif ($event->name === 'Model.PedidoCliente.Update') {
            $doc = new PedidoCliente();
            $docType = 'pedido';
        } else {
            return $this->done();
        }

        if (false === $doc->load($event->value)) {
            return $this->done();
        }

        $this->createPendingEnrolments($doc, $docType);
        return $this->done();
    }

    private function createPendingEnrolments($doc, string $docType): void
    {
        $contactId = $this->getContactId($doc);
        if (empty($contactId)) {
            return;
        }

        foreach ($doc->getLines() as $line) {
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
                continue;
            }

            // check if enrolment already exists
            $enrolment = new MoodleEnrolment();
            $enWhere = [
                new DataBaseWhere('idinstance', $courseMap->idinstance),
                new DataBaseWhere('moodle_userid', $userMap->moodle_userid),
                new DataBaseWhere('moodle_courseid', $courseMap->moodle_courseid),
            ];
            if ($enrolment->loadFromCode('', $enWhere)) {
                continue;
            }

            $enrolment->idinstance = $courseMap->idinstance;
            $enrolment->idcontacto = $contactId;
            $enrolment->moodle_userid = $userMap->moodle_userid;
            $enrolment->moodle_courseid = $courseMap->moodle_courseid;
            $enrolment->idcourse_map = $courseMap->id;
            $enrolment->enrolment_method = 'manual';
            $enrolment->roleid = MoodleRoleMap::resolveRoleForContact($courseMap->idinstance, $contactId);
            $enrolment->status = 'pending';

            if ($docType === 'presupuesto') {
                $enrolment->idpresupuesto = $doc->idpresupuesto;
                $enrolment->notes = Tools::lang()->trans('pre-enrolment');
            } else {
                $enrolment->idpedido = $doc->idpedido;
                $enrolment->notes = Tools::lang()->trans('provisional-enrolment');
            }

            $enrolment->save();
        }
    }

    private function getContactId($doc): int
    {
        if (!empty($doc->idcontactofact)) {
            return (int)$doc->idcontactofact;
        }

        $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
        if ($cliente->load($doc->codcliente) && !empty($cliente->idcontactofact)) {
            return (int)$cliente->idcontactofact;
        }

        return 0;
    }
}
