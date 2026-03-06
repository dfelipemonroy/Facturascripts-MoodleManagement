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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

class EditMoodleInstance extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleInstance';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-instance';
        $data['icon'] = 'fa-solid fa-graduation-cap';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        $this->addListView('ListMoodleUserMap', 'MoodleUserMap', 'moodle-users', 'fa-solid fa-users')
            ->addOrderBy(['last_sync'], 'last-sync', 2)
            ->addSearchFields(['moodle_username']);

        $this->addListView('ListMoodleCohort', 'MoodleCohort', 'moodle-cohorts', 'fa-solid fa-people-group')
            ->addOrderBy(['name'], 'name', 1)
            ->addSearchFields(['name', 'idnumber']);

        $this->addListView('ListMoodleCourseMap', 'MoodleCourseMap', 'moodle-courses', 'fa-solid fa-book')
            ->addOrderBy(['fullname'], 'name', 1)
            ->addSearchFields(['shortname', 'fullname']);

        $this->addListView('ListMoodleCourseCategory', 'MoodleCourseCategory', 'moodle-course-categories', 'fa-solid fa-folder-tree')
            ->addOrderBy(['name'], 'name', 1)
            ->addSearchFields(['name']);
    }

    protected function loadData($viewName, $view)
    {
        if (in_array($viewName, ['ListMoodleUserMap', 'ListMoodleCohort', 'ListMoodleCourseMap', 'ListMoodleCourseCategory'])) {
            $idinstance = $this->getViewModelValue($this->getMainViewName(), 'id');
            $where = [new DataBaseWhere('idinstance', $idinstance)];
            $view->loadData('', $where);
            return;
        }

        parent::loadData($viewName, $view);
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'test-connection') {
            $this->testConnectionAction();
            return true;
        }

        return parent::execPreviousAction($action);
    }

    private function testConnectionAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        if (empty($model->token)) {
            Tools::log()->warning('moodle-token-required');
            return;
        }

        $result = MoodleClient::testConnection($model);

        if (isset($result['exception'])) {
            MoodleClient::applyError($model, $result);
            $model->save();
            Tools::log()->error('connection-failed', ['%message%' => $model->last_error]);
            return;
        }

        MoodleClient::applySiteInfo($model, $result);
        $model->save();
        Tools::log()->notice('connection-successful');
    }
}
