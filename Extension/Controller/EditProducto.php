<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\MoodleManagement\Lib\Controller\ProductoMoodleDecorator;

/**
 * FS pipe()-based extension of EditProducto.
 *
 * @since 2.0 — F8.3 refactor: the closures now use ONLY the
 *   FS-sanctioned public API ({@see ExtendedController\EditController}):
 *     - $this->addListView()
 *     - $this->getViewModelValue()
 *     - $this->getMainViewName()
 *     - $this->setSettings()
 *   Direct access to $this->views[X]->model (a protected
 *   BaseController property) was removed — it was an
 *   encapsulation break that could collide with other plugins
 *   that wrap the same view (StockAvanzado, NeoTheme…).
 *
 *   Any Moodle-specific business logic now lives in
 *   {@see ProductoMoodleDecorator::syncCourseMap()} which the
 *   extension calls with a pure data payload.
 */
class EditProducto
{
    protected function createViews(): Closure
    {
        return function () {
            $this->addListView('ListMoodleCourseMap', 'MoodleCourseMap', 'moodle-courses', 'fa-solid fa-graduation-cap')
                ->addOrderBy(['last_sync'], 'last-sync', 2)
                ->addSearchFields(['shortname', 'fullname']);
        };
    }

    public function loadData(): Closure
    {
        return function ($viewName, $view) {
            if ($viewName === 'ListMoodleCourseMap') {
                $mainView = $this->getMainViewName();
                $idproducto = $this->getViewModelValue($mainView, 'idproducto');
                $isCourse = $this->getViewModelValue($mainView, 'moodle_course');
                if (empty($isCourse)) {
                    $this->setSettings('ListMoodleCourseMap', 'active', false);
                }
                $where = [new DataBaseWhere('idproducto', $idproducto)];
                $view->loadData('', $where);
            }
        };
    }

    public function execAfterAction(): Closure
    {
        return function ($action) {
            if (!in_array($action, ['edit', 'insert'], true)) {
                return;
            }

            $mainView = $this->getMainViewName();

            // F8.3 — extract every field through getViewModelValue
            // (public API). No reaching into $this->views[...]->model.
            ProductoMoodleDecorator::syncCourseMap([
                'idproducto'    => (int) $this->getViewModelValue($mainView, 'idproducto'),
                'is_course'     => (bool) $this->getViewModelValue($mainView, 'moodle_course'),
                'referencia'    => (string) $this->getViewModelValue($mainView, 'referencia'),
                'descripcion'   => (string) $this->getViewModelValue($mainView, 'descripcion'),
                'observaciones' => (string) $this->getViewModelValue($mainView, 'observaciones'),
                'precio'        => (float) $this->getViewModelValue($mainView, 'precio'),
            ]);
        };
    }
}
