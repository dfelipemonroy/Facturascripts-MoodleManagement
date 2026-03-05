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

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class ListMoodleUserMap extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-user-mappings';
        $data['icon'] = 'fa-solid fa-users-between-lines';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleUserMap', 'MoodleUserMap', 'moodle-user-mappings', 'fa-solid fa-users-between-lines')
            ->addSearchFields(['moodle_username'])
            ->addOrderBy(['moodle_username'], 'moodle-username', 1)
            ->addOrderBy(['last_sync'], 'last-sync')
            ->addOrderBy(['creation_date'], 'creation-date');

        $instances = [];
        $instanceModel = new MoodleInstance();
        foreach ($instanceModel->all([], ['name' => 'ASC'], 0, 0) as $inst) {
            $instances[] = ['code' => $inst->id, 'description' => $inst->name];
        }
        $this->addFilterSelect('ListMoodleUserMap', 'idinstance', 'moodle-instance', 'idinstance', $instances);

        $directions = [
            ['code' => 'bidirectional', 'description' => Tools::trans('bidirectional')],
            ['code' => 'fs_to_moodle', 'description' => Tools::trans('fs-to-moodle')],
            ['code' => 'moodle_to_fs', 'description' => Tools::trans('moodle-to-fs')],
        ];
        $this->addFilterSelect('ListMoodleUserMap', 'sync_direction', 'sync-direction', 'sync_direction', $directions);
    }
}
