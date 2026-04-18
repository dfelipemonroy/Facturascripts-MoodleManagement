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

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Lib\Contact\ContactTimestampUpdater;

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

    /**
     * F7.4 — update `contactos.mm_last_modified` whenever the
     * operator saves the contact. ContactSyncWorker uses this
     * column to tell whether the FS-side record has mutated since
     * the last Moodle sync, replacing the buggy `fechaalta`
     * heuristic that never moves past the original insert.
     *
     * Uses a raw UPDATE so the write does NOT re-trigger
     * Model.Contacto.Update (which would loop into
     * ContactSyncWorker and debounce into itself).
     *
     * @since 2.0
     */
    public function execAfterAction(): Closure
    {
        return function ($action) {
            if ($action !== 'save-ok' && $action !== 'save-data') {
                return;
            }
            $idcontacto = (int) $this->getViewModelValue($this->getMainViewName(), 'idcontacto');
            // BE-07 (2026-04-17) — raw SQL moved to
            // `ContactTimestampUpdater::touch` so it can be covered
            // by tests and reused from workers.
            ContactTimestampUpdater::touch($idcontacto);
        };
    }
}
