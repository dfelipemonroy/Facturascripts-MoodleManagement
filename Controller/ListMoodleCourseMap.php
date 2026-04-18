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
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class ListMoodleCourseMap extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-courses';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleCourseMap', 'MoodleCourseMap', 'moodle-courses', 'fa-solid fa-book')
            ->addSearchFields(['shortname', 'fullname'])
            ->addOrderBy(['fullname'], 'name', 1)
            ->addOrderBy(['price'], 'price')
            ->addOrderBy(['enrolled_count'], 'enrolled-count')
            ->addOrderBy(['last_sync'], 'last-sync');

        $this->addFilterSelect('ListMoodleCourseMap', 'idinstance', 'moodle-instance', 'idinstance', [
            ['code' => '', 'description' => '------'],
        ], 'moodle_instances', 'id', 'name');

        $this->addFilterSelect('ListMoodleCourseMap', 'source', 'source', 'source', [
            ['code' => '', 'description' => '------'],
            ['code' => 'fs_managed', 'description' => 'fs_managed'],
            ['code' => 'moodle_managed', 'description' => 'moodle_managed'],
            ['code' => 'synced', 'description' => 'synced'],
        ]);

        $this->addFilterCheckbox('ListMoodleCourseMap', 'visible', 'visible', 'visible');
        $this->addFilterCheckbox('ListMoodleCourseMap', 'sync_active', 'sync-active', 'sync_active');

        $this->addButton('ListMoodleCourseMap', [
            'action' => 'import-courses-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-courses-from-moodle',
            'type' => 'modal',
        ]);

        $this->addButton('ListMoodleCourseMap', [
            'action' => 'push-active-courses-to-moodle',
            'icon' => 'fa-solid fa-upload',
            'label' => 'push-active-to-moodle',
            'type' => 'action',
            'color' => 'warning',
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'import-courses-from-moodle':
                $this->importCoursesFromMoodle();
                return true;

            case 'push-active-courses-to-moodle':
                $this->pushActiveCoursesToMoodle();
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

    private function pushActiveCoursesToMoodle(): void
    {
        $where = [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('sync_active', true)];
        $maps = (new MoodleCourseMap())->all($where, [], 0, 0);

        $pushed = 0;
        $errors = 0;

        foreach ($maps as $map) {
            $instance = $map->getInstance();
            if (empty($instance->id) || empty($instance->token)) {
                Tools::log()->warning('course-sync-error', [
                    '%name%' => $map->shortname,
                    '%error%' => Tools::lang()->trans('instance-not-found'),
                ]);
                $errors++;
                continue;
            }

            $courseData = MoodleClient::mapToMoodleCourse($map);

            if (!empty($map->moodle_courseid)) {
                $result = MoodleClient::updateCourse($instance, $map->moodle_courseid, $courseData);
            } else {
                $result = MoodleClient::createCourse($instance, $courseData);
                if (is_array($result) && !empty($result[0]['id'])) {
                    $map->moodle_courseid = $result[0]['id'];
                }
            }

            if (isset($result['exception'])) {
                $map->last_error = $result['message'] ?? $result['exception'];
                $map->save();
                Tools::log()->warning('course-sync-error', [
                    '%name%' => $map->shortname,
                    '%error%' => $map->last_error,
                ]);
                $errors++;
                continue;
            }

            if (empty($map->moodle_categoryid) && !empty($courseData['categoryid'])) {
                $map->moodle_categoryid = $courseData['categoryid'];
            }

            $map->last_sync = date('Y-m-d H:i:s');
            $map->last_error = '';
            $map->save();
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
