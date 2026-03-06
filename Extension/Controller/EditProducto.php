<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

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
            if (!in_array($action, ['edit', 'insert'])) {
                return;
            }

            $mainView = $this->getMainViewName();
            $idproducto = $this->getViewModelValue($mainView, 'idproducto');
            $isCourse = $this->getViewModelValue($mainView, 'moodle_course');

            if (empty($isCourse) || empty($idproducto)) {
                return;
            }

            // check if a MoodleCourseMap already exists for this product
            $existing = new MoodleCourseMap();
            $where = [new DataBaseWhere('idproducto', $idproducto)];
            if ($existing->loadFromCode('', $where)) {
                // sync price from variant to course map
                if ($existing->syncPriceFromProduct()) {
                    $existing->save();
                }
                return;
            }

            // find the first active Moodle instance
            $instance = new MoodleInstance();
            $whereInstance = [new DataBaseWhere('status', 'active')];
            if (false === $instance->loadFromCode('', $whereInstance)) {
                Tools::log()->warning('no-active-moodle-instance');
                return;
            }

            // create a local-only course map (not synced to Moodle)
            $producto = $this->views[$mainView]->model;
            $map = new MoodleCourseMap();
            $map->idinstance = $instance->id;
            $map->idproducto = $idproducto;
            $map->shortname = $producto->referencia ?? '';
            $map->fullname = $producto->descripcion ?? '';
            $map->summary = $producto->observaciones ?? '';
            $map->price = $producto->precio ?? 0;
            $map->sync_active = false;
            $map->source = 'fs_managed';
            $map->save();
        };
    }
}
