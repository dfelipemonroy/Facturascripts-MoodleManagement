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
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class EditMoodleCohort extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleCohort';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-cohort';
        $data['icon'] = 'fa-solid fa-people-group';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        // Tab: cohort members (mapped users in this cohort's instance)
        $this->addListView('ListMoodleUserMap', 'MoodleUserMap', 'moodle-users', 'fa-solid fa-users')
            ->addOrderBy(['moodle_username'], 'moodle-username', 1)
            ->addSearchFields(['moodle_username']);
    }

    protected function loadData($viewName, $view)
    {
        if ($viewName === 'ListMoodleUserMap') {
            $idinstance = $this->getViewModelValue($this->getMainViewName(), 'idinstance');
            $where = [new DataBaseWhere('idinstance', $idinstance)];
            $view->loadData('', $where);
            return;
        }

        parent::loadData($viewName, $view);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'sync-cohort':
                $this->syncCohortAction();
                return true;

            case 'push-cohort-to-moodle':
                $this->pushCohortToMoodleAction();
                return true;

            case 'sync-cohort-members':
                $this->syncCohortMembersAction();
                return true;

            case 'enrol-cohort-to-course':
                $this->enrolCohortToCourseAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function syncCohortAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        if (empty($model->moodle_cohortid)) {
            Tools::log()->warning('moodle-cohortid-required');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        // Get cohort info from Moodle
        $result = MoodleClient::getCohorts($instance, [$model->moodle_cohortid]);

        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%message%' => $result['message'] ?? $result['exception']]);
            return;
        }

        if (!empty($result) && isset($result[0])) {
            $cohortData = $result[0];
            $model->name = $cohortData['name'] ?? $model->name;
            $model->idnumber = $cohortData['idnumber'] ?? '';
            $model->description = $cohortData['description'] ?? '';
        }

        // Get member count
        $members = MoodleClient::getCohortMembers($instance, [$model->moodle_cohortid]);
        if (!isset($members['exception']) && !empty($members) && isset($members[0]['userids'])) {
            $model->member_count = count($members[0]['userids']);
        }

        $model->last_sync = date('Y-m-d H:i:s');
        $model->save();
        Tools::log()->notice('cohort-synced');
    }

    private function pushCohortToMoodleAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        if (empty($model->moodle_cohortid)) {
            // Create cohort in Moodle
            $cohortData = [
                'categorytype' => ['type' => 'system', 'value' => ''],
                'name' => $model->name,
                'idnumber' => $model->idnumber ?: '',
                'description' => $model->description ?: '',
            ];
            $result = MoodleClient::createCohort($instance, $cohortData);

            if (isset($result['exception'])) {
                Tools::log()->error('sync-failed', ['%message%' => $result['message'] ?? $result['exception']]);
                return;
            }

            if (is_array($result) && isset($result[0]['id'])) {
                $model->moodle_cohortid = $result[0]['id'];
            }
        } else {
            // Update cohort in Moodle
            $result = MoodleClient::updateCohort($instance, $model->moodle_cohortid, [
                'name' => $model->name,
                'idnumber' => $model->idnumber ?: '',
                'description' => $model->description ?: '',
            ]);

            if (isset($result['exception'])) {
                Tools::log()->error('sync-failed', ['%message%' => $result['message'] ?? $result['exception']]);
                return;
            }
        }

        // If linked to a customer group, sync members
        if (!empty($model->codgrupo)) {
            $this->syncCohortMembersFromGroup($model, $instance);
        }

        $model->last_sync = date('Y-m-d H:i:s');
        $model->save();
        Tools::log()->notice('cohort-pushed');
    }

    private function syncCohortMembersFromGroup($model, $instance): void
    {
        // Find all mapped users whose contact is linked to a customer in this group
        $db = $this->dataBase;
        $sql = "SELECT m.moodle_userid FROM moodle_user_map m"
            . " INNER JOIN contactos c ON c.idcontacto = m.idcontacto"
            . " INNER JOIN clientes cl ON cl.codcliente = c.codcliente"
            . " WHERE cl.codgrupo = " . $db->var2str($model->codgrupo)
            . " AND m.idinstance = " . $db->var2str($model->idinstance)
            . " AND m.moodle_userid > 0";

        $rows = $db->select($sql);
        if (empty($rows)) {
            return;
        }

        $userIds = array_map(function ($row) {
            return (int)$row['moodle_userid'];
        }, $rows);

        MoodleClient::addCohortMembers($instance, $model->moodle_cohortid, $userIds);
        $model->member_count = count($userIds);
    }

    private function syncCohortMembersAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        if (empty($model->moodle_cohortid)) {
            Tools::log()->warning('moodle-cohortid-required');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        // get members from Moodle
        $members = MoodleClient::getCohortMembers($instance, [$model->moodle_cohortid]);
        if (isset($members['exception'])) {
            Tools::log()->error('sync-failed', ['%message%' => $members['message'] ?? $members['exception']]);
            return;
        }

        $userIds = $members[0]['userids'] ?? [];
        $model->member_count = count($userIds);

        // for each Moodle user, ensure a MoodleUserMap exists
        $created = 0;
        foreach ($userIds as $moodleUserId) {
            $userMap = new MoodleUserMap();
            $umWhere = [
                new DataBaseWhere('idinstance', $model->idinstance),
                new DataBaseWhere('moodle_userid', $moodleUserId),
            ];

            if (false === $userMap->loadFromCode('', $umWhere)) {
                // get user info from Moodle to create mapping
                $userInfo = MoodleClient::getUsersByField($instance, 'id', [$moodleUserId]);
                if (isset($userInfo['exception']) || empty($userInfo) || empty($userInfo[0]['id'])) {
                    continue;
                }

                $userMap->idinstance = $model->idinstance;
                $userMap->moodle_userid = (int)$userInfo[0]['id'];
                $userMap->moodle_username = $userInfo[0]['username'] ?? '';
                $userMap->source = 'moodle_managed';
                $userMap->last_sync = date('Y-m-d H:i:s');
                if ($userMap->save()) {
                    $created++;
                }
            }
        }

        $model->last_sync = date('Y-m-d H:i:s');
        $model->save();

        Tools::log()->notice('cohort-members-synced', ['%count%' => count($userIds), '%created%' => $created]);
    }

    private function enrolCohortToCourseAction(): void
    {
        $model = $this->getModel();
        $code = $this->request->request->get('code', $this->request->query->get('code', ''));
        if (!empty($code)) {
            $model->loadFromCode($code);
        }

        if (empty($model->moodle_cohortid)) {
            Tools::log()->warning('moodle-cohortid-required');
            return;
        }

        $courseMapId = (int)$this->request->request->get('enrol_coursemap_id', 0);
        $roleid = (int)$this->request->request->get('enrol_roleid', 5);
        if ($roleid <= 0) {
            $roleid = 5;
        }

        $courseMap = new MoodleCourseMap();
        if (empty($courseMapId) || false === $courseMap->loadFromCode($courseMapId)) {
            Tools::log()->warning('no-course-map');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        // get cohort member user IDs from Moodle
        $members = MoodleClient::getCohortMembers($instance, [$model->moodle_cohortid]);
        if (isset($members['exception']) || empty($members) || empty($members[0]['userids'])) {
            Tools::log()->warning('no-records-selected');
            return;
        }

        $userIds = $members[0]['userids'];
        $success = 0;
        $errors = 0;

        foreach ($userIds as $moodleUserId) {
            $enrolment = new MoodleEnrolment();
            $where = [
                new DataBaseWhere('idinstance', $courseMap->idinstance),
                new DataBaseWhere('moodle_userid', $moodleUserId),
                new DataBaseWhere('moodle_courseid', $courseMap->moodle_courseid),
            ];

            if (false === $enrolment->loadFromCode('', $where)) {
                // find contact via MoodleUserMap
                $userMap = new MoodleUserMap();
                $umWhere = [
                    new DataBaseWhere('idinstance', $courseMap->idinstance),
                    new DataBaseWhere('moodle_userid', $moodleUserId),
                ];
                $contactId = $userMap->loadFromCode('', $umWhere) ? (int)$userMap->idcontacto : 0;

                $enrolment->idinstance = $courseMap->idinstance;
                $enrolment->idcontacto = $contactId;
                $enrolment->moodle_userid = $moodleUserId;
                $enrolment->moodle_courseid = $courseMap->moodle_courseid;
                $enrolment->idcourse_map = $courseMap->id;
                $enrolment->enrolment_method = 'cohort';
                $enrolment->roleid = $roleid;
            }

            if ($enrolment->enrol()) {
                $success++;
            } else {
                $errors++;
            }
        }

        if ($success > 0) {
            Tools::log()->notice('batch-action-success', ['%count%' => $success]);
        }
        if ($errors > 0) {
            Tools::log()->warning('batch-action-errors', ['%count%' => $errors]);
        }
    }
}
