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

namespace FacturaScripts\Plugins\MoodleManagement\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

class EditContacto
{
    protected function createViews(): Closure
    {
        return function () {
            $this->addListView('ListMoodleUserMap', 'MoodleUserMap', 'moodle-users', 'fa-solid fa-graduation-cap')
                ->addOrderBy(['last_sync'], 'last-sync', 2)
                ->addSearchFields(['moodle_username']);

            $this->addListView('ListMoodleEnrolment', 'MoodleEnrolment', 'moodle-enrolments', 'fa-solid fa-user-graduate')
                ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
                ->addSearchFields(['moodle_courseid', 'notes']);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName === 'ListMoodleUserMap') {
                $idcontacto = $this->getViewModelValue($this->getMainViewName(), 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            } elseif ($viewName === 'ListMoodleEnrolment') {
                $idcontacto = $this->getViewModelValue($this->getMainViewName(), 'idcontacto');
                $where = [new DataBaseWhere('idcontacto', $idcontacto)];
                $view->loadData('', $where);
            }
        };
    }
}
