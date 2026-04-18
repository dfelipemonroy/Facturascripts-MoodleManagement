<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Lib\ClienteTrainingHelper;

class EditCliente
{
    protected function createViews(): Closure
    {
        return function (): void {
            $this->addHtmlView('ClienteFormacion', 'Tab/ClienteFormacion', 'Cliente', 'moodle-training', 'fa-solid fa-graduation-cap');

            $this->addListView('ListMoodleEnrolmentCliente', 'MoodleEnrolment', 'moodle-enrolments', 'fa-solid fa-user-graduate')
                ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
                ->addSearchFields(['moodle_courseid', 'notes']);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view): void {
            if ($viewName === 'ClienteFormacion') {
                $codcliente = $this->getViewModelValue($this->getMainViewName(), 'codcliente');
                if (empty($codcliente)) {
                    return;
                }
                $view->settings['trainingData'] = ClienteTrainingHelper::getTrainingData($codcliente);
            } elseif ($viewName === 'ListMoodleEnrolmentCliente') {
                $codcliente = $this->getViewModelValue($this->getMainViewName(), 'codcliente');
                if (empty($codcliente)) {
                    return;
                }
                $db = new DataBase();
                $contactIds = [];
                $sql = 'SELECT idcontacto FROM contactos WHERE codcliente = ' . $db->var2str($codcliente);
                foreach ($db->select($sql) as $row) {
                    $contactIds[] = (int)$row['idcontacto'];
                }
                if (empty($contactIds)) {
                    return;
                }
                $where = [new DataBaseWhere('idcontacto', implode(',', $contactIds), 'IN')];
                $view->loadData('', $where);
            }
        };
    }
}
