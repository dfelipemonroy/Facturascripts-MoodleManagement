<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseCategory;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class ListMoodleCourseCategory extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-course-categories';
        $data['icon'] = 'fa-solid fa-folder-tree';
        return $data;
    }

    protected function createViews(): void
    {
        $this->addView('ListMoodleCourseCategory', 'MoodleCourseCategory', 'moodle-course-categories', 'fa-solid fa-folder-tree')
            ->addSearchFields(['name', 'description'])
            ->addOrderBy(['name'], 'name', 1)
            ->addOrderBy(['last_sync'], 'last-sync');

        $this->addFilterSelect('ListMoodleCourseCategory', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        $this->addFilterSelect('ListMoodleCourseCategory', 'source', 'source', 'source', [
            ['code' => '', 'description' => '------'],
            ['code' => 'fs_managed', 'description' => 'fs_managed'],
            ['code' => 'moodle_managed', 'description' => 'moodle_managed'],
            ['code' => 'synced', 'description' => 'synced'],
        ]);

        $parentValues = [['code' => '', 'description' => '------']];
        foreach ((new \FacturaScripts\Dinamic\Model\CodeModel())::all('MoodleCourseCategory', 'moodle_categoryid', 'name', false) as $cm) {
            $parentValues[] = ['code' => $cm->code, 'description' => $cm->description];
        }
        $this->addFilterSelect('ListMoodleCourseCategory', 'parent_categoryid', 'parent-category', 'parent_categoryid', $parentValues);

        $this->addFilterCheckbox('ListMoodleCourseCategory', 'sync_active', 'sync-active', 'sync_active');

        $this->addButton('ListMoodleCourseCategory', [
            'action' => 'import-categories-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-categories-from-moodle',
            'type' => 'modal',
        ]);

        $this->addButton('ListMoodleCourseCategory', [
            'action' => 'push-active-categories-to-moodle',
            'icon' => 'fa-solid fa-upload',
            'label' => 'push-active-to-moodle',
            'type' => 'action',
            'color' => 'warning',
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'import-categories-from-moodle':
                $this->importCategoriesFromMoodle();
                return true;

            case 'push-active-categories-to-moodle':
                $this->pushActiveCategoriesToMoodle();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function importCategoriesFromMoodle(): void
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

        $result = MoodleClient::getCategories($instance);
        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($result as $catData) {
            if (!is_array($catData) || empty($catData['id'])) {
                continue;
            }

            $existing = MoodleCourseCategory::findByMoodleCategoryId($idinstance, (int)$catData['id']);
            if ($existing) {
                $skipped++;
                continue;
            }

            $cat = new MoodleCourseCategory();
            $cat->idinstance = $idinstance;
            $cat->moodle_categoryid = $catData['id'];
            $cat->name = $catData['name'] ?? '';
            $cat->description = strip_tags($catData['description'] ?? '');
            $cat->parent_categoryid = !empty($catData['parent']) ? (int)$catData['parent'] : null;
            $cat->visible = (bool)($catData['visible'] ?? true);
            $cat->source = 'moodle_managed';
            $cat->last_sync = date('Y-m-d H:i:s');

            if ($cat->save()) {
                $imported++;
            }
        }

        Tools::log()->notice('moodle-categories-imported', [
            '%imported%' => $imported,
            '%skipped%' => $skipped,
        ]);
    }

    private function pushActiveCategoriesToMoodle(): void
    {
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('sync_active', true)];
        $categories = (new MoodleCourseCategory())->all($where, [], 0, 0);

        $pushed = 0;
        $errors = 0;

        foreach ($categories as $cat) {
            $instance = $cat->getInstance();
            if (empty($instance->id) || empty($instance->token)) {
                Tools::log()->warning('category-sync-error', [
                    '%name%' => $cat->name,
                    '%error%' => Tools::lang()->trans('instance-not-found'),
                ]);
                $errors++;
                continue;
            }

            $categoryData = [
                'name' => $cat->name,
                'description' => $cat->description ?? '',
                'parent' => $cat->parent_categoryid ?: 0,
            ];

            if (!empty($cat->moodle_categoryid)) {
                $result = MoodleClient::updateCategory($instance, $cat->moodle_categoryid, $categoryData);
            } else {
                $result = MoodleClient::createCategory($instance, $categoryData);
                if (is_array($result) && !empty($result[0]['id'])) {
                    $cat->moodle_categoryid = $result[0]['id'];
                }
            }

            if (isset($result['exception'])) {
                Tools::log()->warning('category-sync-error', [
                    '%name%' => $cat->name,
                    '%error%' => $result['message'] ?? $result['exception'],
                ]);
                $errors++;
                continue;
            }

            $cat->last_sync = date('Y-m-d H:i:s');
            $cat->save();
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
}
