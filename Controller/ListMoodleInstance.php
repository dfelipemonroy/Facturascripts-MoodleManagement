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

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;

class ListMoodleInstance extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-instances';
        $data['icon'] = 'fa-solid fa-graduation-cap';
        return $data;
    }

    protected function createViews()
    {
        $route = Tools::config('route');
        AssetManager::addJs($route . '/Plugins/MoodleManagement/Assets/JS/MoodleStatus.js');

        $this->addView('ListMoodleInstance', 'MoodleInstance', 'moodle-instances', 'fa-solid fa-graduation-cap')
            ->addSearchFields(['name', 'url', 'site_name'])
            ->addOrderBy(['name'], 'name', 1)
            ->addOrderBy(['status'], 'status')
            ->addOrderBy(['last_check'], 'last-check')
            ->addOrderBy(['environment'], 'environment');

        $statuses = [
            ['code' => 'active', 'description' => Tools::trans('active')],
            ['code' => 'inactive', 'description' => Tools::trans('inactive')],
            ['code' => 'maintenance', 'description' => Tools::trans('maintenance')],
            ['code' => 'unreachable', 'description' => Tools::trans('unreachable')],
        ];
        $this->addFilterSelect('ListMoodleInstance', 'status', 'status', 'status', $statuses);

        $environments = [
            ['code' => 'production', 'description' => Tools::trans('production')],
            ['code' => 'staging', 'description' => Tools::trans('staging')],
            ['code' => 'development', 'description' => Tools::trans('development')],
        ];
        $this->addFilterSelect('ListMoodleInstance', 'environment', 'environment', 'environment', $environments);

        $this->addFilterSelect('ListMoodleInstance', 'idempresa', 'company', 'idempresa', Empresas::codeModel());
    }
}
