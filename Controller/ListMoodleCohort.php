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

class ListMoodleCohort extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-cohorts';
        $data['icon'] = 'fa-solid fa-people-group';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleCohort', 'MoodleCohort', 'moodle-cohorts', 'fa-solid fa-people-group')
            ->addSearchFields(['name', 'idnumber', 'description'])
            ->addOrderBy(['name'], 'name', 1)
            ->addOrderBy(['member_count'], 'member-count')
            ->addOrderBy(['last_sync'], 'last-sync');

        $instances = [];
        $instanceModel = new MoodleInstance();
        foreach ($instanceModel->all([], ['name' => 'ASC'], 0, 0) as $inst) {
            $instances[] = ['code' => $inst->id, 'description' => $inst->name];
        }
        $this->addFilterSelect('ListMoodleCohort', 'idinstance', 'moodle-instance', 'idinstance', $instances);

        $sources = [
            ['code' => 'fs_managed', 'description' => Tools::trans('fs-managed')],
            ['code' => 'moodle_managed', 'description' => Tools::trans('moodle-managed')],
            ['code' => 'synced', 'description' => Tools::trans('synced')],
        ];
        $this->addFilterSelect('ListMoodleCohort', 'source', 'source', 'source', $sources);
    }
}
