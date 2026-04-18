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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\CohortLifecycle;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCohort;
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

        $this->addFilterSelect('ListMoodleCohort', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        $this->addFilterSelect('ListMoodleCohort', 'source', 'source', 'source', [
            ['code' => '', 'description' => '------'],
            ['code' => 'fs_managed', 'description' => 'fs_managed'],
            ['code' => 'moodle_managed', 'description' => 'moodle_managed'],
            ['code' => 'synced', 'description' => 'synced'],
        ]);

        $this->addFilterCheckbox('ListMoodleCohort', 'sync_active', 'sync-active', 'sync_active');

        // F13 DISCOVERED-02 / F18.33 (ex-U4 migrated from v2.1 backlog)
        // — hide soft-deleted rows by default, and expose the four
        // `CohortLifecycle` states as explicit filter options backed
        // by `source / sync_active / deleted_at` predicates.
        $this->addFilterSelectWhere('ListMoodleCohort', 'lifecycle', [
            [
                'label'   => Tools::lang()->trans('active'),
                'where'   => [new DataBaseWhere('deleted_at', null, 'IS')],
                'default' => true,
            ],
            [
                'label' => Tools::lang()->trans('all'),
                'where' => [],
            ],
            [
                'label' => Tools::lang()->trans(CohortLifecycle::TRASHED),
                'where' => [new DataBaseWhere('deleted_at', null, 'IS NOT')],
            ],
            [
                'label' => Tools::lang()->trans(CohortLifecycle::DETACHED),
                'where' => [
                    new DataBaseWhere('deleted_at', null, 'IS'),
                    new DataBaseWhere('sync_active', false),
                ],
            ],
        ]);

        $this->addButton('ListMoodleCohort', [
            'action' => 'import-cohorts-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-cohorts-from-moodle',
            'type' => 'modal',
        ]);

        $this->addButton('ListMoodleCohort', [
            'action' => 'sync-all-cohorts',
            'icon' => 'fa-solid fa-arrows-rotate',
            'label' => 'sync-all',
            'type' => 'action',
            'color' => 'info',
        ]);

        $this->addButton('ListMoodleCohort', [
            'action' => 'push-active-cohorts-to-moodle',
            'icon' => 'fa-solid fa-upload',
            'label' => 'push-active-to-moodle',
            'type' => 'action',
            'color' => 'warning',
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'import-cohorts-from-moodle':
                $this->importCohortsFromMoodle();
                return true;

            case 'sync-all-cohorts':
                $this->syncAllCohorts();
                return true;

            case 'push-active-cohorts-to-moodle':
                $this->pushActiveCohortsToMoodle();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function importCohortsFromMoodle(): void
    {
        $idinstance = (int)$this->request->request->get('idinstance', '');
        if (empty($idinstance)) {
            Tools::log()->warning('instance-required');
            return;
        }

        $instance = new MoodleInstance();
        if (false === $instance->loadFromCode($idinstance)) {
            Tools::log()->error('instance-not-found');
            return;
        }

        // search all cohorts using empty query to get all
        $result = MoodleClient::searchCohorts($instance, '');
        if (isset($result['exception'])) {
            Tools::log()->error('import-failed', ['%message%' => $result['message'] ?? $result['exception']]);
            return;
        }

        // searchCohorts returns { cohorts: [...], ... }
        $cohorts = $result['cohorts'] ?? $result;
        if (!is_array($cohorts)) {
            Tools::log()->warning('import-failed', ['%message%' => 'Invalid response']);
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($cohorts as $cohortData) {
            if (!is_array($cohortData) || empty($cohortData['id'])) {
                continue;
            }

            // check if already exists
            $existing = new MoodleCohort();
            $where = [
                new DataBaseWhere('idinstance', $idinstance),
                new DataBaseWhere('moodle_cohortid', $cohortData['id']),
            ];
            if (false !== $existing->loadFromCode('', $where)) {
                // update existing
                $existing->name = $cohortData['name'] ?? $existing->name;
                $existing->idnumber = $cohortData['idnumber'] ?? '';
                $existing->description = strip_tags($cohortData['description'] ?? '');
                $existing->last_sync = date('Y-m-d H:i:s');
                $existing->save();
                $skipped++;
                continue;
            }

            $cohort = new MoodleCohort();
            $cohort->idinstance = $idinstance;
            $cohort->moodle_cohortid = (int)$cohortData['id'];
            $cohort->name = $cohortData['name'] ?? '';
            $cohort->idnumber = $cohortData['idnumber'] ?? '';
            $cohort->description = strip_tags($cohortData['description'] ?? '');
            $cohort->source = 'moodle_managed';
            $cohort->last_sync = date('Y-m-d H:i:s');

            // get member count
            $members = MoodleClient::getCohortMembers($instance, [$cohort->moodle_cohortid]);
            if (!isset($members['exception']) && !empty($members[0]['userids'])) {
                $cohort->member_count = count($members[0]['userids']);
            }

            if ($cohort->save()) {
                $imported++;
            }
        }

        Tools::log()->notice('moodle-cohorts-imported', [
            '%imported%' => $imported,
            '%skipped%' => $skipped,
        ]);
    }

    private function syncAllCohorts(): void
    {
        $where = [new DataBaseWhere('moodle_cohortid', 0, '>')];
        $cohorts = (new MoodleCohort())->all($where, [], 0, 0);

        $synced = 0;
        $errors = 0;

        foreach ($cohorts as $cohort) {

            $instance = $cohort->getInstance();
            if (empty($instance->id) || empty($instance->token)) {
                Tools::log()->warning('cohort-sync-error', [
                    '%name%' => $cohort->name,
                    '%error%' => Tools::lang()->trans('instance-not-found'),
                ]);
                $errors++;
                continue;
            }

            $result = MoodleClient::getCohorts($instance, [$cohort->moodle_cohortid]);
            if (isset($result['exception'])) {
                Tools::log()->warning('cohort-sync-error', [
                    '%name%' => $cohort->name,
                    '%error%' => $result['message'] ?? $result['exception'],
                ]);
                $errors++;
                continue;
            }

            if (!empty($result) && isset($result[0])) {
                $cohortData = $result[0];
                $cohort->name = $cohortData['name'] ?? $cohort->name;
                $cohort->idnumber = $cohortData['idnumber'] ?? '';
                $cohort->description = $cohortData['description'] ?? '';
            }

            // sync member count
            $members = MoodleClient::getCohortMembers($instance, [$cohort->moodle_cohortid]);
            if (!isset($members['exception']) && !empty($members[0]['userids'])) {
                $cohort->member_count = count($members[0]['userids']);
            } else {
                $cohort->member_count = 0;
            }

            $cohort->last_sync = date('Y-m-d H:i:s');
            $cohort->save();
            $synced++;
        }

        if ($errors > 0) {
            Tools::log()->error('sync-completed', [
                '%synced%' => $synced,
                '%errors%' => $errors,
            ]);
        } else {
            Tools::log()->notice('sync-completed', [
                '%synced%' => $synced,
                '%errors%' => $errors,
            ]);
        }
    }

    private function pushActiveCohortsToMoodle(): void
    {
        $where = [new DataBaseWhere('sync_active', true)];
        $cohorts = (new MoodleCohort())->all($where, [], 0, 0);

        $pushed = 0;
        $errors = 0;

        foreach ($cohorts as $cohort) {
            $instance = $cohort->getInstance();
            if (empty($instance->id) || empty($instance->token)) {
                Tools::log()->warning('cohort-sync-error', [
                    '%name%' => $cohort->name,
                    '%error%' => Tools::lang()->trans('instance-not-found'),
                ]);
                $errors++;
                continue;
            }

            $cohortData = [
                'categorytype' => ['type' => 'system', 'value' => ''],
                'name' => $cohort->name,
                'idnumber' => $cohort->idnumber ?: '',
                'description' => $cohort->description ?: '',
            ];

            if (!empty($cohort->moodle_cohortid)) {
                $result = MoodleClient::updateCohort($instance, $cohort->moodle_cohortid, [
                    'name' => $cohort->name,
                    'idnumber' => $cohort->idnumber ?: '',
                    'description' => $cohort->description ?: '',
                ]);
            } else {
                $result = MoodleClient::createCohort($instance, $cohortData);
                if (is_array($result) && isset($result[0]['id'])) {
                    $cohort->moodle_cohortid = $result[0]['id'];
                }
            }

            if (isset($result['exception'])) {
                Tools::log()->warning('cohort-sync-error', [
                    '%name%' => $cohort->name,
                    '%error%' => $result['message'] ?? $result['exception'],
                ]);
                $errors++;
                continue;
            }

            // sync members from customer group if linked
            if (!empty($cohort->codgrupo)) {
                $this->syncCohortMembersFromGroup($cohort, $instance);
            }

            $cohort->last_sync = date('Y-m-d H:i:s');
            $cohort->save();
            $pushed++;
        }

        if ($errors > 0) {
            Tools::log()->error('sync-completed', [
                '%synced%' => $pushed,
                '%errors%' => $errors,
            ]);
        } else {
            Tools::log()->notice('sync-completed', [
                '%synced%' => $pushed,
                '%errors%' => $errors,
            ]);
        }
    }

    private function syncCohortMembersFromGroup(MoodleCohort $model, MoodleInstance $instance): void
    {
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
}
