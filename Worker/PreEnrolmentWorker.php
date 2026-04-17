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

/**
 * Creates `pending` MoodleEnrolment rows when a Presupuesto or
 * Pedido is saved with lines that map to Moodle courses.
 *
 * No Moodle API call is made here: the enrolments stay as 'pending'
 * in the local DB until the corresponding invoice is marked as paid,
 * at which point EnrolmentWorker turns them into real Moodle
 * enrolments.
 *
 * Listens to `Model.PresupuestoCliente.Update` and
 * `Model.PedidoCliente.Update`. Line-delete propagation is added in
 * Fase 6 F6.10.
 *
 * @since 2.0 PHPDoc completed (existed since 1.1)
 */
class PreEnrolmentWorker extends WorkerClass
{
    /**
     * @param WorkEvent $event $event->value = Presupuesto or Pedido PK
     *                         depending on $event->name.
     * @return bool True once $this->done() is called.
     */
    /**
     * Cache-key prefix used by Cron::generateRenewalEstimate() to
     * signal this worker that a given Presupuesto PK was created
     * by the renewal cron and does not need re-processing. Must
     * match the constant in Cron.php.
     *
     * @since 2.0 F6.2 · §1.2
     */
    private const SKIP_PREENROL_PREFIX = 'mm:pre-enrol-skip:presupuesto:';

    public function run(WorkEvent $event): bool
    {
        // F6.10 — Line delete events: if the operator removes a line
        // from a quote/order, any pending MoodleEnrolment that was
        // seeded for that line stays orphan. We cannot resolve the
        // parent document from the event (FS only carries the line
        // PK), so the worker logs the event and the next
        // reconciliation cron cleans the orphans up. Keeping the
        // subscription in place means the plugin reacts to the
        // stream of deletions in observability without adding a
        // tight loop.
        if (
            $event->name === 'Model.LineaPresupuestoCliente.Delete'
            || $event->name === 'Model.LineaPedidoCliente.Delete'
        ) {
            \FacturaScripts\Core\Tools::log()->info('preenrol-line-deleted', [
                'event' => $event->name,
                'id'    => (int) $event->value,
            ]);
            return $this->done();
        }

        if ($event->name === 'Model.PresupuestoCliente.Update') {
            $doc = new PresupuestoCliente();
            $docType = 'presupuesto';

            // F6.2 — cut the renewal cascade: if Cron:: generate-
            // RenewalEstimate() flagged this id, consume the flag
            // and bail out. Any enrolments linked to it were
            // already persisted by the cron itself.
            $skipKey = self::SKIP_PREENROL_PREFIX . (int) $event->value;
            if (\FacturaScripts\Core\Tools::cache()->get($skipKey)) {
                \FacturaScripts\Core\Tools::cache()->delete($skipKey);
                return $this->done();
            }
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

    /**
     * Iterates the document lines and creates a MoodleEnrolment row
     * per line whose product is mapped to a Moodle course. Skips
     * lines whose enrolment already exists (idempotent).
     *
     * @param PresupuestoCliente|PedidoCliente $doc
     * @param string $docType Either 'presupuesto' or 'pedido'.
     * @return void
     */
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

    /**
     * Resolves the billing contact PK from the document:
     *   1. Use $doc->idcontactofact if set.
     *   2. Else load the Cliente by codcliente and use its
     *      idcontactofact.
     *   3. Else return 0 (caller must treat as "no contact").
     *
     * @param PresupuestoCliente|PedidoCliente $doc
     * @return int idcontacto or 0 if none found.
     */
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
