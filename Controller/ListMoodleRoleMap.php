<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleRoleMap;

class ListMoodleRoleMap extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-role-mappings';
        $data['icon'] = 'fa-solid fa-user-shield';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleRoleMap', 'MoodleRoleMap', 'moodle-role-mappings', 'fa-solid fa-user-shield')
            ->addSearchFields(['moodle_role_shortname', 'description'])
            ->addOrderBy(['moodle_roleid'], 'roleid', 1)
            ->addOrderBy(['moodle_role_shortname'], 'moodle-role-shortname')
            ->addOrderBy(['creation_date'], 'creation-date');

        $this->setSettings('ListMoodleRoleMap', 'btnNew', false);
        $this->setSettings('ListMoodleRoleMap', 'clickable', false);

        $this->addFilterSelect('ListMoodleRoleMap', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        $this->addFilterSelect('ListMoodleRoleMap', 'context_level', 'context-level', 'context_level', [
            ['code' => '', 'description' => '------'],
            ['code' => 'course', 'description' => 'course'],
            ['code' => 'system', 'description' => 'system'],
        ]);

        $this->addFilterCheckbox('ListMoodleRoleMap', 'is_default', 'is-default', 'is_default');

        $this->addButton('ListMoodleRoleMap', [
            'action' => 'import-standard-roles',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-standard-roles',
            'type' => 'modal',
        ]);
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        if ($viewName === 'ListMoodleRoleMap') {
            Tools::log()->info('roles-api-notice');
        }
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'import-standard-roles') {
            $this->importStandardRoles();
            return true;
        }

        return parent::execPreviousAction($action);
    }

    private function importStandardRoles(): void
    {
        $idinstance = (int)$this->request->request->get('idinstance', '');
        if (empty($idinstance)) {
            Tools::log()->warning('instance-required');
            return;
        }

        $instance = new MoodleInstance();
        if (false === $instance->loadFromCode($idinstance)) {
            Tools::log()->error('moodle-instance-not-found');
            return;
        }

        $standardRoles = MoodleRoleMap::getStandardRoles();
        $imported = 0;
        $skipped = 0;

        foreach ($standardRoles as $roleid => $shortname) {
            $existing = new MoodleRoleMap();
            $where = [
                new DataBaseWhere('idinstance', $idinstance),
                new DataBaseWhere('moodle_roleid', $roleid),
            ];
            if ($existing->loadFromCode('', $where)) {
                if (empty($existing->description)) {
                    $existing->description = Tools::lang()->trans('role-' . $shortname);
                    $existing->save();
                }
                $skipped++;
                continue;
            }

            $roleMap = new MoodleRoleMap();
            $roleMap->idinstance = $idinstance;
            $roleMap->moodle_roleid = $roleid;
            $roleMap->moodle_role_shortname = $shortname;
            $roleMap->description = Tools::lang()->trans('role-' . $shortname);
            $roleMap->context_level = in_array($roleid, [7, 8]) ? 'system' : 'course';
            $roleMap->is_default = ($roleid === 5);

            if ($roleMap->save()) {
                $imported++;
            }
        }

        Tools::log()->notice('roles-imported', [
            '%imported%' => $imported,
            '%skipped%' => $skipped,
        ]);
    }
}
