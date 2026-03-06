<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseCategory;

class EditMoodleCourseCategory extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleCourseCategory';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-course-category';
        $data['icon'] = 'fa-solid fa-folder-tree';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        $this->addListView('ListMoodleCourseMap', 'MoodleCourseMap', 'moodle-courses', 'fa-solid fa-book')
            ->addSearchFields(['shortname', 'fullname'])
            ->addOrderBy(['fullname'], 'name', 1);
    }

    protected function loadData($viewName, $view)
    {
        if ($viewName === 'ListMoodleCourseMap') {
            $model = $this->getModel();
            $where = [
                new DataBaseWhere('idinstance', $model->idinstance),
                new DataBaseWhere('moodle_categoryid', $model->moodle_categoryid),
            ];
            $view->loadData('', $where);
            return;
        }

        parent::loadData($viewName, $view);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'sync-category':
                $this->syncCategoryFromMoodle();
                return true;

            case 'push-category-to-moodle':
                $this->pushCategoryToMoodle();
                return true;

            case 'create-familia':
                $this->createFamiliaFromCategory();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function syncCategoryFromMoodle(): void
    {
        /** @var MoodleCourseCategory $cat */
        $cat = $this->getModel();
        if (empty($cat->moodle_categoryid)) {
            Tools::log()->warning('moodle-category-id-required');
            return;
        }

        $instance = $cat->getInstance();
        $result = MoodleClient::getCategories($instance, [
            ['key' => 'id', 'value' => $cat->moodle_categoryid],
        ], false);

        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        if (!empty($result) && is_array($result[0] ?? null)) {
            $data = $result[0];
            $cat->name = $data['name'] ?? $cat->name;
            $cat->description = strip_tags($data['description'] ?? '');
            $cat->parent_categoryid = !empty($data['parent']) ? (int)$data['parent'] : null;
            $cat->visible = (bool)($data['visible'] ?? true);
            $cat->last_sync = date('Y-m-d H:i:s');

            if ($cat->save()) {
                Tools::log()->notice('category-synced');
            }
        }
    }

    private function pushCategoryToMoodle(): void
    {
        /** @var MoodleCourseCategory $cat */
        $cat = $this->getModel();
        $instance = $cat->getInstance();

        $data = [
            'name' => $cat->name,
            'description' => $cat->description ?? '',
            'parent' => $cat->parent_categoryid ?: 0,
        ];

        if (!empty($cat->moodle_categoryid)) {
            $result = MoodleClient::updateCategory($instance, $cat->moodle_categoryid, $data);
        } else {
            $result = MoodleClient::createCategory($instance, $data);
            if (is_array($result) && !empty($result[0]['id'])) {
                $cat->moodle_categoryid = $result[0]['id'];
            }
        }

        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        $cat->last_sync = date('Y-m-d H:i:s');
        $cat->save();
        Tools::log()->notice('category-pushed');
    }

    private function createFamiliaFromCategory(): void
    {
        /** @var MoodleCourseCategory $cat */
        $cat = $this->getModel();

        if (!empty($cat->codfamilia)) {
            Tools::log()->warning('family-already-linked');
            return;
        }

        $familia = new Familia();
        // Generate code: max 8 chars, alphanumeric
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $cat->name), 0, 8));
        if (empty($code)) {
            $code = 'MDL' . $cat->id;
        }
        $familia->codfamilia = $code;
        $familia->descripcion = substr($cat->name, 0, 100);

        if (false === $familia->save()) {
            Tools::log()->error('family-creation-failed');
            return;
        }

        $cat->codfamilia = $familia->codfamilia;
        $cat->save();
        Tools::log()->notice('family-created', ['%code%' => $familia->codfamilia]);
    }
}
