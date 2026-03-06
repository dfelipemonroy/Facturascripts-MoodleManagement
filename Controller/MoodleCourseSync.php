<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseCategory;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class MoodleCourseSync extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-course-sync';
        $data['icon'] = 'fa-solid fa-arrows-rotate';
        return $data;
    }

    protected function createViews()
    {
        // Courses tab
        $this->addView('ListMoodleCourseMap', 'MoodleCourseMap', 'moodle-courses', 'fa-solid fa-book')
            ->addSearchFields(['shortname', 'fullname'])
            ->addOrderBy(['fullname'], 'name', 1)
            ->addOrderBy(['last_sync'], 'last-sync')
            ->addOrderBy(['enrolled_count'], 'enrolled-count');

        $this->addFilterSelect('ListMoodleCourseMap', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        // Import button
        $this->addButton('ListMoodleCourseMap', [
            'action' => 'import-courses-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-courses-from-moodle',
            'type' => 'modal',
        ]);

        // Sync all button
        $this->addButton('ListMoodleCourseMap', [
            'action' => 'sync-all-courses',
            'icon' => 'fa-solid fa-arrows-rotate',
            'label' => 'sync-all-courses',
            'type' => 'action',
            'color' => 'info',
        ]);

        // Create products button
        $this->addButton('ListMoodleCourseMap', [
            'action' => 'create-products-for-all',
            'icon' => 'fa-solid fa-box',
            'label' => 'create-products-for-unmapped',
            'type' => 'action',
            'color' => 'success',
        ]);

        // Categories tab
        $this->addView('ListMoodleCourseCategory', 'MoodleCourseCategory', 'moodle-course-categories', 'fa-solid fa-folder-tree')
            ->addSearchFields(['name', 'description'])
            ->addOrderBy(['name'], 'name', 1);

        $this->addFilterSelect('ListMoodleCourseCategory', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        $this->addButton('ListMoodleCourseCategory', [
            'action' => 'import-categories-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-categories-from-moodle',
            'type' => 'modal',
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'import-courses-from-moodle':
                $this->importCoursesFromMoodle();
                return true;

            case 'import-categories-from-moodle':
                $this->importCategoriesFromMoodle();
                return true;

            case 'sync-all-courses':
                $this->syncAllCourses();
                return true;

            case 'create-products-for-all':
                $this->createProductsForAll();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function importCoursesFromMoodle(): void
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

        $result = MoodleClient::getCourses($instance);
        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        $imported = 0;
        $updated = 0;

        foreach ($result as $course) {
            if (!is_array($course) || empty($course['id'])) {
                continue;
            }

            // Skip site-level course (id=1)
            if ((int)$course['id'] === 1) {
                continue;
            }

            $map = new MoodleCourseMap();
            $where = [
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idinstance', $idinstance),
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('moodle_courseid', $course['id']),
            ];
            $isNew = false === $map->loadFromCode('', $where);

            if ($isNew) {
                $map->idinstance = $idinstance;
                $map->source = 'moodle_managed';
            }

            MoodleClient::moodleCourseToMap($map, $course);
            $map->last_sync = date('Y-m-d H:i:s');

            if ($map->save()) {
                if ($isNew) {
                    $map->createLinkedProduct();
                    $imported++;
                } else {
                    $updated++;
                }

                // sync image if product exists
                if (!empty($map->idproducto) && !empty($map->moodle_courseid)) {
                    $overviewFiles = MoodleClient::getOverviewFiles($instance, (int)$map->moodle_courseid);
                    if (!empty($overviewFiles)) {
                        $map->syncImageToProduct($overviewFiles);
                    }
                }
            }
        }

        Tools::log()->notice('moodle-courses-imported', [
            '%imported%' => $imported,
            '%skipped%' => $updated,
        ]);
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

    private function syncAllCourses(): void
    {
        $maps = (new MoodleCourseMap())->all([], [], 0, 0);
        $synced = 0;
        $errors = 0;

        // Group by instance
        $byInstance = [];
        foreach ($maps as $map) {
            if (!empty($map->moodle_courseid)) {
                $byInstance[$map->idinstance][] = $map;
            }
        }

        foreach ($byInstance as $idinstance => $instanceMaps) {
            $instance = new MoodleInstance();
            if (false === $instance->loadFromCode($idinstance)) {
                continue;
            }

            $courseIds = array_map(fn($m) => $m->moodle_courseid, $instanceMaps);
            $result = MoodleClient::getCourses($instance, $courseIds);

            if (isset($result['exception'])) {
                $errors += count($instanceMaps);
                continue;
            }

            // Index by ID
            $courseById = [];
            foreach ($result as $c) {
                if (is_array($c) && !empty($c['id'])) {
                    $courseById[$c['id']] = $c;
                }
            }

            foreach ($instanceMaps as $map) {
                if (isset($courseById[$map->moodle_courseid])) {
                    MoodleClient::moodleCourseToMap($map, $courseById[$map->moodle_courseid]);
                    $map->last_sync = date('Y-m-d H:i:s');
                    $map->last_error = '';
                    $map->save();
                    $synced++;
                } else {
                    $map->last_error = 'Course not found in Moodle';
                    $map->save();
                    $errors++;
                }
            }
        }

        Tools::log()->notice('sync-completed', [
            '%synced%' => $synced,
            '%errors%' => $errors,
        ]);
    }

    private function createProductsForAll(): void
    {
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idproducto', null, 'IS')];
        $maps = (new MoodleCourseMap())->all($where, [], 0, 0);
        $created = 0;

        foreach ($maps as $map) {
            if ($map->createLinkedProduct()) {
                $created++;
            }
        }

        Tools::log()->notice('products-created-count', ['%count%' => $created]);
    }
}
