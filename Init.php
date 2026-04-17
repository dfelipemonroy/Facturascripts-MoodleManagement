<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Lib\Widget\BaseWidget;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\MoodleManagement\Lib\Widget\WidgetMoodleTimestamp;

/**
 * Class Init
 *
 * @package FacturaScripts\Plugins\MoodleManagement
 */
class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditContacto());
        $this->loadExtension(new Extension\Controller\EditCliente());
        $this->loadExtension(new Extension\Controller\EditProducto());

        WorkQueue::addWorker('EnrolmentWorker', 'Model.FacturaCliente.Update');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.PresupuestoCliente.Update');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.PedidoCliente.Update');
        WorkQueue::addWorker('ContactSyncWorker', 'Model.Contacto.Update');
        WorkQueue::addWorker('ContactDeleteWorker', 'Model.Contacto.Delete');
        WorkQueue::addWorker('BadgeSyncWorker', 'Model.MoodleUserMap.Save');
        WorkQueue::addWorker('OnboardingWorker', 'Model.MoodleUserMap.Insert');

        // F3.7 — register custom widget for Moodle Unix-epoch INT columns.
        // Using addExtension so BaseWidget::widgetClass() resolves the
        // class when an XMLView declares <widget type="moodleTimestamp">.
        if (method_exists(BaseWidget::class, 'addExtension')) {
            BaseWidget::addExtension('moodleTimestamp', WidgetMoodleTimestamp::class);
        }
    }

    public function uninstall(): void
    {
    }

    public function update(): void
    {
        $this->createViews();
    }

    private function createViews(): void
    {
        $db = Tools::config('db_type') === 'postgresql'
            ? "CREATE OR REPLACE VIEW moodle_course_categories_view AS SELECT moodle_categoryid, name || ' (' || moodle_categoryid || ')' AS display_name FROM moodle_course_categories WHERE moodle_categoryid IS NOT NULL"
            : "CREATE OR REPLACE VIEW moodle_course_categories_view AS SELECT moodle_categoryid, CONCAT(name, ' (', moodle_categoryid, ')') AS display_name FROM moodle_course_categories WHERE moodle_categoryid IS NOT NULL";

        (new DataBase())->exec($db);
    }
}
