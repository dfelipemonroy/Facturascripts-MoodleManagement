<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;

class EditMoodleCourseMap extends EditController
{
    /** @var array */
    public $courseContents = [];

    /** @var string */
    public $courseContentError = '';

    /** @var array */
    public $enrolmentMethods = [];

    /** @var string */
    public $enrolmentMethodsError = '';

    /** @var array|null Cached module detail for modal display */
    public $moduleDetail = null;

    /** @var array */
    public $courseGroups = [];

    /** @var string */
    public $courseGroupsError = '';

    /** @var array */
    public $courseGroupMembers = [];

    /** @var array Enrolled users in the course [{id, fullname, username}, ...] */
    public $enrolledUsers = [];

    public function getModelClassName(): string
    {
        return 'MoodleCourseMap';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-course';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        $this->addListView('ListMoodleEnrolment', 'MoodleEnrolment', 'moodle-enrolments', 'fa-solid fa-user-graduate')
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
            ->addSearchFields(['moodle_userid', 'notes']);

        $this->addHtmlView('CourseContent', 'Tab/CourseContent', 'MoodleCourseMap', 'course-content', 'fa-solid fa-list-ul');
        $this->addHtmlView('CourseGroups', 'Tab/CourseGroups', 'MoodleCourseMap', 'groups', 'fa-solid fa-people-group');
        $this->addHtmlView('EnrolmentMethods', 'Tab/EnrolmentMethods', 'MoodleCourseMap', 'enrolment-methods', 'fa-solid fa-key');
        $this->addHtmlView('CourseMessaging', 'Tab/CourseMessaging', 'MoodleCourseMap', 'messaging', 'fa-solid fa-paper-plane');
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ListMoodleEnrolment':
                $map = $this->getModel();
                if (!empty($map->moodle_courseid) && !empty($map->idinstance)) {
                    $where = [
                        new DataBaseWhere('moodle_courseid', $map->moodle_courseid),
                        new DataBaseWhere('idinstance', $map->idinstance),
                    ];
                    $view->loadData('', $where);
                }
                break;

            case 'CourseContent':
                $this->loadCourseContent();
                $view->count = $this->getCourseContentSummary()['totalModules'];
                break;

            case 'CourseGroups':
                $this->loadCourseGroups();
                $view->count = count($this->courseGroups);
                break;

            case 'CourseMessaging':
                $this->loadEnrolledUsersIfNeeded();
                break;

            case 'EnrolmentMethods':
                $this->loadEnrolmentMethods();
                break;

            default:
                parent::loadData($viewName, $view);
        }
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'sync-course':
                $this->syncCourseFromMoodle();
                return true;

            case 'push-course-to-moodle':
                $this->pushCourseToMoodle();
                return true;

            case 'create-product':
                $this->createProductFromCourse();
                return true;

            case 'sync-course-image':
                $this->syncCourseImage();
                return true;

            case 'duplicate-course':
                $this->duplicateCourse();
                return true;

            case 'add-meta-enrolment':
                $this->addMetaEnrolment();
                return true;

            // Course content management actions
            case 'cm-show':
            case 'cm-hide':
            case 'cm-stealth':
            case 'cm-duplicate':
            case 'cm-delete':
            case 'cm-move':
            case 'cm-moveright':
            case 'cm-moveleft':
            case 'cm-nogroups':
            case 'cm-visiblegroups':
            case 'cm-separategroups':
                $this->executeModuleAction($action);
                return true;

            case 'section-add':
            case 'section-show':
            case 'section-hide':
            case 'section-delete':
            case 'section-move':
                $this->executeSectionAction($action);
                return true;

            case 'module-detail':
                $this->loadModuleDetail();
                return true;

            case 'group-create':
                $this->createGroupAction();
                return true;

            case 'group-delete':
                $this->deleteGroupAction();
                return true;

            case 'group-add-member':
                $this->addGroupMemberAction();
                return true;

            case 'group-remove-member':
                $this->removeGroupMemberAction();
                return true;

            case 'send-course-message':
                $this->sendCourseMessageAction();
                return true;

            case 'enrol-batch':
            case 'unenrol-batch':
            case 'suspend-batch':
                $this->processEnrolmentBatch($action);
                return true;
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Loads the model from code parameter (needed in execPreviousAction for modal actions).
     */
    private function loadModel(): MoodleCourseMap
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        $code = $this->request->request->get('code', $this->request->query->get('code', ''));
        if (!empty($code) && empty($map->primaryColumnValue())) {
            $map->loadFromCode($code);
        }
        return $map;
    }

    public function getCourseContentSummary(): array
    {
        $totalSections = count($this->courseContents);
        $totalModules = 0;
        $byType = [];

        foreach ($this->courseContents as $section) {
            $modules = $section['modules'] ?? [];
            $totalModules += count($modules);
            foreach ($modules as $module) {
                $type = $module['modname'] ?? 'unknown';
                $byType[$type] = ($byType[$type] ?? 0) + 1;
            }
        }

        arsort($byType);

        return [
            'totalSections' => $totalSections,
            'totalModules' => $totalModules,
            'byType' => $byType,
        ];
    }

    private function loadCourseContent(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        if (empty($map->moodle_courseid)) {
            return;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            $this->courseContentError = Tools::lang()->trans('moodle-instance-not-configured');
            return;
        }

        $result = MoodleClient::getCourseContents($instance, $map->moodle_courseid);
        if (isset($result['exception'])) {
            $this->courseContentError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->courseContents = is_array($result) ? $result : [];
    }

    private function loadEnrolmentMethods(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        if (empty($map->moodle_courseid)) {
            return;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            $this->enrolmentMethodsError = Tools::lang()->trans('moodle-instance-not-configured');
            return;
        }

        $result = MoodleClient::getCourseEnrolmentMethods($instance, $map->moodle_courseid);
        if (isset($result['exception'])) {
            $this->enrolmentMethodsError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->enrolmentMethods = is_array($result) ? $result : [];
    }

    private function syncCourseFromMoodle(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        if (empty($map->moodle_courseid)) {
            Tools::log()->warning('moodle-courseid-required');
            return;
        }

        $instance = $map->getInstance();
        $result = MoodleClient::getCourses($instance, [$map->moodle_courseid]);

        if (isset($result['exception'])) {
            $map->last_error = $result['message'] ?? $result['exception'];
            $map->save();
            Tools::log()->error('sync-failed', ['%error%' => $map->last_error]);
            return;
        }

        if (empty($result) || !is_array($result)) {
            Tools::log()->warning('course-not-found-in-moodle');
            return;
        }

        $courseData = is_array($result[0] ?? null) ? $result[0] : $result;
        MoodleClient::moodleCourseToMap($map, $courseData);
        $map->last_sync = date('Y-m-d H:i:s');
        $map->last_error = '';

        if ($map->save()) {
            // sync image if product exists
            if (!empty($map->idproducto)) {
                $overviewFiles = MoodleClient::getOverviewFiles($instance, $map->moodle_courseid);
                if (!empty($overviewFiles)) {
                    $map->syncImageToProduct($overviewFiles);
                }
            }
            Tools::log()->notice('course-synced');
        }
    }

    private function pushCourseToMoodle(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        $instance = $map->getInstance();
        $courseData = MoodleClient::mapToMoodleCourse($map);

        if (!empty($map->moodle_courseid)) {
            $result = MoodleClient::updateCourse($instance, $map->moodle_courseid, $courseData);
            if (isset($result['exception'])) {
                $map->last_error = $result['message'] ?? $result['exception'];
                $map->save();
                Tools::log()->error('sync-failed', ['%error%' => $map->last_error]);
                return;
            }
            Tools::log()->notice('course-pushed');
        } else {
            $result = MoodleClient::createCourse($instance, $courseData);
            if (isset($result['exception'])) {
                $map->last_error = $result['message'] ?? $result['exception'];
                $map->save();
                Tools::log()->error('sync-failed', ['%error%' => $map->last_error]);
                return;
            }
            if (is_array($result) && !empty($result[0]['id'])) {
                $map->moodle_courseid = $result[0]['id'];
                Tools::log()->notice('course-pushed');
            }
        }

        if (empty($map->moodle_categoryid) && !empty($courseData['categoryid'])) {
            $map->moodle_categoryid = $courseData['categoryid'];
        }

        $map->last_sync = date('Y-m-d H:i:s');
        $map->last_error = '';
        $map->save();
    }

    private function createProductFromCourse(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();

        if (!empty($map->idproducto)) {
            Tools::log()->warning('product-already-linked');
            return;
        }

        if ($map->createLinkedProduct()) {
            Tools::log()->notice('product-created', ['%reference%' => 'MDL-' . ($map->moodle_courseid ?: $map->id)]);
        } else {
            Tools::log()->error('product-creation-failed');
        }
    }

    /**
     * Sync course image from Moodle to FS.
     * Downloads the course overview image and sets it as cover.
     */
    private function syncCourseImage(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->loadModel();

        if (empty($map->moodle_courseid)) {
            Tools::log()->warning('moodle-courseid-required');
            return;
        }

        if (empty($map->idproducto)) {
            Tools::log()->warning('product-required-for-image-sync');
            return;
        }

        $instance = $map->getInstance();
        $overviewFiles = MoodleClient::getOverviewFiles($instance, $map->moodle_courseid);

        if (empty($overviewFiles)) {
            Tools::log()->warning('no-course-image');
            return;
        }

        if ($map->syncImageToProduct($overviewFiles, true)) {
            Tools::log()->notice('course-image-synced');
        } else {
            Tools::log()->error('course-image-sync-failed');
        }
    }

    private function duplicateCourse(): void
    {
        $map = $this->loadModel();

        if (empty($map->moodle_courseid)) {
            Tools::log()->warning('moodle-courseid-required');
            return;
        }

        $newFullname = $this->request->request->get('new_fullname', '');
        $newShortname = $this->request->request->get('new_shortname', '');
        $newCategoryId = (int)$this->request->request->get('new_categoryid', $map->moodle_categoryid ?: 1);

        if (empty($newFullname) || empty($newShortname)) {
            Tools::log()->warning('fullname-and-shortname-required');
            return;
        }

        $instance = $map->getInstance();
        $result = MoodleClient::duplicateCourse(
            $instance,
            $map->moodle_courseid,
            $newFullname,
            $newShortname,
            $newCategoryId,
            (bool)$map->visible
        );

        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        $newCourseId = $result['id'] ?? 0;
        if (empty($newCourseId)) {
            Tools::log()->error('sync-failed', ['%error%' => 'No course ID returned']);
            return;
        }

        // Create new MoodleCourseMap for the duplicated course
        $newMap = new MoodleCourseMap();
        $newMap->idinstance = $map->idinstance;
        $newMap->moodle_courseid = $newCourseId;
        $newMap->shortname = $newShortname;
        $newMap->fullname = $newFullname;
        $newMap->summary = $map->summary;
        $newMap->moodle_categoryid = $newCategoryId;
        $newMap->format = $map->format;
        $newMap->startdate = $map->startdate;
        $newMap->enddate = $map->enddate;
        $newMap->visible = $map->visible;
        $newMap->price = $map->price;
        $newMap->currency = $map->currency;
        $newMap->source = $map->source;
        $newMap->sync_active = $map->sync_active;
        $newMap->last_sync = date('Y-m-d H:i:s');

        if ($newMap->save()) {
            $newMap->createLinkedProduct();
            Tools::log()->notice('course-duplicated', ['%name%' => $newShortname]);
            $this->redirect($newMap->url());
        } else {
            Tools::log()->error('product-creation-failed');
        }
    }

    private function addMetaEnrolment(): void
    {
        $map = $this->loadModel();

        if (empty($map->moodle_courseid)) {
            Tools::log()->warning('moodle-courseid-required');
            return;
        }

        $linkedMapId = (int)$this->request->request->get('meta_linked_coursemap_id', 0);
        if (empty($linkedMapId)) {
            Tools::log()->warning('no-course-map');
            return;
        }

        $linkedMap = new MoodleCourseMap();
        if (false === $linkedMap->loadFromCode($linkedMapId) || empty($linkedMap->moodle_courseid)) {
            Tools::log()->warning('no-course-map');
            return;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        $result = MoodleClient::addMetaEnrolInstances($instance, $map->moodle_courseid, [$linkedMap->moodle_courseid]);

        if (isset($result['exception'])) {
            Tools::log()->error('sync-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice('meta-enrolment-added', ['%course%' => $linkedMap->shortname]);
    }

    /**
     * Load the MoodleCourseMap and its MoodleInstance, validating both.
     * Returns [map, instance] or null if validation fails.
     */
    private function loadMapAndInstance(): ?array
    {
        $map = $this->loadModel();

        if (empty($map->moodle_courseid)) {
            Tools::log()->warning('no-course-linked');
            return null;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return null;
        }

        if ($instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return null;
        }

        return [$map, $instance];
    }

    /**
     * Execute a module-level action (cm-show, cm-hide, cm-move, etc.)
     */
    private function executeModuleAction(string $action): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        /** @var MoodleCourseMap $map */
        /** @var MoodleInstance $instance */
        [$map, $instance] = $data;

        $cmids = $this->getIntArrayFromRequest('cmids');
        if (empty($cmids)) {
            Tools::log()->warning('no-modules-selected');
            return;
        }

        $courseId = (int)$map->moodle_courseid;

        $count = count($cmids);
        $idList = implode(', ', $cmids);

        switch ($action) {
            case 'cm-show':
                $result = MoodleClient::showModules($instance, $courseId, $cmids);
                $successKey = 'modules-shown';
                break;
            case 'cm-hide':
                $result = MoodleClient::hideModules($instance, $courseId, $cmids);
                $successKey = 'modules-hidden';
                break;
            case 'cm-stealth':
                $result = MoodleClient::stealthModules($instance, $courseId, $cmids);
                $successKey = 'modules-stealthed';
                break;
            case 'cm-duplicate':
                $result = MoodleClient::duplicateModules($instance, $courseId, $cmids);
                $successKey = 'modules-duplicated';
                break;
            case 'cm-delete':
                $result = MoodleClient::deleteModules($instance, $cmids);
                $successKey = 'modules-deleted';
                break;
            case 'cm-move':
                $targetSectionId = (int)$this->request->request->get('target_section_id', 0);
                if (empty($targetSectionId)) {
                    Tools::log()->warning('move-to-section');
                    return;
                }
                $result = MoodleClient::moveModules($instance, $courseId, $cmids, $targetSectionId);
                $successKey = 'modules-moved';
                break;
            case 'cm-moveright':
                $result = MoodleClient::indentRight($instance, $courseId, $cmids);
                $successKey = 'modules-indented-right';
                break;
            case 'cm-moveleft':
                $result = MoodleClient::indentLeft($instance, $courseId, $cmids);
                $successKey = 'modules-indented-left';
                break;
            case 'cm-nogroups':
                $result = MoodleClient::setNoGroups($instance, $courseId, $cmids);
                $successKey = 'modules-groupmode-changed';
                break;
            case 'cm-visiblegroups':
                $result = MoodleClient::setVisibleGroups($instance, $courseId, $cmids);
                $successKey = 'modules-groupmode-changed';
                break;
            case 'cm-separategroups':
                $result = MoodleClient::setSeparateGroups($instance, $courseId, $cmids);
                $successKey = 'modules-groupmode-changed';
                break;
            default:
                return;
        }

        $this->handleCourseActionResult($result, $successKey, ['%count%' => $count, '%ids%' => $idList]);
    }

    /**
     * Execute a section-level action (section-add, section-show, etc.)
     */
    private function executeSectionAction(string $action): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        /** @var MoodleCourseMap $map */
        /** @var MoodleInstance $instance */
        [$map, $instance] = $data;

        $courseId = (int)$map->moodle_courseid;

        switch ($action) {
            case 'section-add':
                $afterSectionId = (int)$this->request->request->get('after_section_id', 0) ?: null;
                $result = MoodleClient::addSection($instance, $courseId, $afterSectionId);
                $successKey = 'section-added';
                $params = [];
                break;

            case 'section-show':
                $sectionIds = $this->getIntArrayFromRequest('section_ids');
                if (empty($sectionIds)) {
                    Tools::log()->warning('no-sections-selected');
                    return;
                }
                $result = MoodleClient::showSections($instance, $courseId, $sectionIds);
                $successKey = 'sections-shown';
                $params = ['%count%' => count($sectionIds)];
                break;

            case 'section-hide':
                $sectionIds = $this->getIntArrayFromRequest('section_ids');
                if (empty($sectionIds)) {
                    Tools::log()->warning('no-sections-selected');
                    return;
                }
                $result = MoodleClient::hideSections($instance, $courseId, $sectionIds);
                $successKey = 'sections-hidden';
                $params = ['%count%' => count($sectionIds)];
                break;

            case 'section-delete':
                $sectionIds = $this->getIntArrayFromRequest('section_ids');
                if (empty($sectionIds)) {
                    Tools::log()->warning('no-sections-selected');
                    return;
                }
                $result = MoodleClient::deleteSections($instance, $courseId, $sectionIds);
                $successKey = 'sections-deleted';
                $params = ['%count%' => count($sectionIds)];
                break;

            case 'section-move':
                $sectionIds = $this->getIntArrayFromRequest('section_ids');
                $afterSectionId = (int)$this->request->request->get('after_section_id',
                    $this->request->request->get('target_section_id', 0));
                if (empty($sectionIds) || empty($afterSectionId)) {
                    Tools::log()->warning('no-sections-selected');
                    return;
                }
                $result = MoodleClient::moveSectionAfter($instance, $courseId, $sectionIds, $afterSectionId);
                $successKey = 'sections-moved';
                $params = ['%count%' => count($sectionIds)];
                break;

            default:
                return;
        }

        $this->handleCourseActionResult($result, $successKey, $params);
    }

    /**
     * Load module detail for modal display.
     */
    private function loadModuleDetail(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [$map, $instance] = $data;

        $cmid = (int)$this->request->request->get('cmid', $this->request->query->get('cmid', 0));
        if (empty($cmid)) {
            return;
        }

        $result = MoodleClient::getCourseModule($instance, $cmid);
        if (isset($result['exception'])) {
            $this->courseContentError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->moduleDetail = $result['cm'] ?? $result;
    }

    /**
     * Handle the result of a course action API call.
     */
    private function handleCourseActionResult(array $result, string $successKey, array $params = []): void
    {
        if (isset($result['exception'])) {
            Tools::log()->error('action-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice($successKey, $params);
    }

    /**
     * Get an array of integers from the request (supports both comma-separated and array).
     */
    private function getIntArrayFromRequest(string $key): array
    {
        $value = $this->request->request->get($key, '');
        if (is_array($value)) {
            return array_map('intval', array_filter($value));
        }
        if (is_string($value) && !empty($value)) {
            return array_map('intval', array_filter(explode(',', $value)));
        }
        return [];
    }

    // ── Group Management ──

    private function loadCourseGroups(): void
    {
        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        if (empty($map->moodle_courseid)) {
            return;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            $this->courseGroupsError = Tools::lang()->trans('moodle-instance-not-configured');
            return;
        }

        if ($instance->status !== 'active') {
            $this->courseGroupsError = Tools::lang()->trans('instance-not-active');
            return;
        }

        $result = MoodleClient::getCourseGroups($instance, $map->moodle_courseid);
        if (isset($result['exception'])) {
            $this->courseGroupsError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->courseGroups = is_array($result) ? $result : [];

        // Load member counts
        if (!empty($this->courseGroups)) {
            $groupIds = array_column($this->courseGroups, 'id');
            $membersResult = MoodleClient::getGroupMembers($instance, $groupIds);
            if (!isset($membersResult['exception']) && is_array($membersResult)) {
                $this->courseGroupMembers = $membersResult;
                // Add member count to each group
                $memberCountMap = [];
                foreach ($membersResult as $gm) {
                    $memberCountMap[$gm['groupid']] = count($gm['userids'] ?? []);
                }
                foreach ($this->courseGroups as &$group) {
                    $group['memberCount'] = $memberCountMap[$group['id']] ?? 0;
                }
                unset($group);
            }
        }

        // Load enrolled users for the select dropdown
        $enrolledResult = MoodleClient::getEnrolledUsers($instance, (int)$map->moodle_courseid);
        if (!isset($enrolledResult['exception']) && is_array($enrolledResult)) {
            foreach ($enrolledResult as $user) {
                $this->enrolledUsers[] = [
                    'id' => $user['id'],
                    'fullname' => $user['fullname'] ?? ($user['firstname'] . ' ' . $user['lastname']),
                    'username' => $user['username'] ?? '',
                ];
            }
            usort($this->enrolledUsers, fn($a, $b) => strcasecmp($a['fullname'], $b['fullname']));
        }
    }

    private function createGroupAction(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [$map, $instance] = $data;

        $name = trim($this->request->request->get('group_name', ''));
        if (empty($name)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'name']);
            return;
        }

        $group = [
            'courseid' => (int)$map->moodle_courseid,
            'name' => $name,
            'description' => $this->request->request->get('group_description', ''),
            'descriptionformat' => 1,
        ];

        $result = MoodleClient::createGroups($instance, [$group]);
        $this->handleCourseActionResult($result, 'group-created');
    }

    private function deleteGroupAction(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [, $instance] = $data;

        $groupId = (int)$this->request->request->get('group_id', 0);
        if (empty($groupId)) {
            return;
        }

        $result = MoodleClient::deleteGroups($instance, [$groupId]);
        $this->handleCourseActionResult($result, 'group-deleted');
    }

    private function addGroupMemberAction(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [, $instance] = $data;

        $groupId = (int)$this->request->request->get('group_id', 0);
        $userId = (int)$this->request->request->get('member_userid', 0);
        if (empty($groupId) || empty($userId)) {
            return;
        }

        $result = MoodleClient::addGroupMembers($instance, [
            ['groupid' => $groupId, 'userid' => $userId],
        ]);
        $this->handleCourseActionResult($result, 'member-added');
    }

    private function removeGroupMemberAction(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [, $instance] = $data;

        $groupId = (int)$this->request->request->get('group_id', 0);
        $userId = (int)$this->request->request->get('member_userid', 0);
        if (empty($groupId) || empty($userId)) {
            return;
        }

        $result = MoodleClient::deleteGroupMembers($instance, [
            ['groupid' => $groupId, 'userid' => $userId],
        ]);
        $this->handleCourseActionResult($result, 'member-removed');
    }

    private function loadEnrolledUsersIfNeeded(): void
    {
        if (!empty($this->enrolledUsers)) {
            return;
        }

        /** @var MoodleCourseMap $map */
        $map = $this->getModel();
        if (empty($map->moodle_courseid)) {
            return;
        }

        $instance = $map->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            return;
        }

        $enrolledResult = MoodleClient::getEnrolledUsers($instance, (int)$map->moodle_courseid);
        if (!isset($enrolledResult['exception']) && is_array($enrolledResult)) {
            foreach ($enrolledResult as $user) {
                $this->enrolledUsers[] = [
                    'id' => $user['id'],
                    'fullname' => $user['fullname'] ?? ($user['firstname'] . ' ' . $user['lastname']),
                    'username' => $user['username'] ?? '',
                ];
            }
            usort($this->enrolledUsers, fn($a, $b) => strcasecmp($a['fullname'], $b['fullname']));
        }
    }

    private function sendCourseMessageAction(): void
    {
        $data = $this->loadMapAndInstance();
        if ($data === null) {
            return;
        }

        [, $instance] = $data;

        $text = trim($this->request->request->get('message_text', ''));
        if (empty($text)) {
            Tools::log()->warning('message-text-required');
            return;
        }

        $recipientMode = $this->request->request->get('recipient_mode', 'all');
        $targetUserIds = [];

        if ($recipientMode === 'selected') {
            $targetUserIds = array_map('intval', array_filter($this->request->request->getArray('recipient_userids')));
        } else {
            $this->loadEnrolledUsersIfNeeded();
            foreach ($this->enrolledUsers as $user) {
                $targetUserIds[] = (int)$user['id'];
            }
        }

        if (empty($targetUserIds)) {
            Tools::log()->warning('no-recipients');
            return;
        }

        $messages = [];
        foreach ($targetUserIds as $userId) {
            $messages[] = ['touserid' => $userId, 'text' => $text];
        }

        $result = MoodleClient::sendInstantMessages($instance, $messages);

        if (isset($result['exception'])) {
            Tools::log()->error('message-send-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        // Check per-message errors
        $sent = 0;
        $errors = [];
        foreach ($result as $msgResult) {
            if (!empty($msgResult['errormessage'])) {
                $errors[] = $msgResult['errormessage'];
            } else {
                $sent++;
            }
        }

        if ($sent > 0) {
            Tools::log()->notice('messages-sent', ['%count%' => $sent]);
        }
        if (!empty($errors)) {
            Tools::log()->warning('message-send-failed', ['%error%' => implode('; ', array_unique($errors))]);
        }
    }

    private function processEnrolmentBatch(string $action): void
    {
        $codes = $this->request->request->getArray('codes');

        if (empty($codes)) {
            Tools::log()->warning('no-records-selected');
            return;
        }

        $model = new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment();
        $success = 0;
        $errors = 0;

        foreach ($codes as $code) {
            if (!$model->loadFromCode($code)) {
                continue;
            }

            $result = match ($action) {
                'enrol-batch' => $model->enrol(),
                'unenrol-batch' => $model->unenrol(),
                'suspend-batch' => $model->suspend(),
                default => false,
            };

            if ($result) {
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
