<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class EditMoodleEnrolment extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleEnrolment';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-enrolment';
        $data['icon'] = 'fa-solid fa-user-graduate';
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        $route = FS_ROUTE;
        AssetManager::addJs($route . '/Plugins/MoodleManagement/Assets/JS/EnrolmentAutofill.js?v=' . Tools::date());
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'enrol-user':
                $this->enrolAction();
                return true;

            case 'unenrol-user':
                $this->unenrolAction();
                return true;

            case 'suspend-user':
                $this->suspendAction();
                return true;

            case 'reactivate-user':
                $this->reactivateAction();
                return true;

            case 'get-moodle-userid':
                $this->getMoodleUserIdAction();
                return false;

            case 'get-moodle-courseid':
                $this->getMoodleCourseIdAction();
                return false;
        }

        return parent::execPreviousAction($action);
    }

    private function loadModel(): MoodleEnrolment
    {
        /** @var MoodleEnrolment $model */
        $model = $this->getModel();
        $code = $this->request->request->get('code', $this->request->query->get('code', ''));
        if (!empty($code) && empty($model->primaryColumnValue())) {
            $model->loadFromCode($code);
        }
        return $model;
    }

    private function enrolAction(): void
    {
        $model = $this->loadModel();
        if ($model->enrol()) {
            Tools::log()->notice('enrol-success');
        } else {
            Tools::log()->error('enrol-failed', ['%error%' => $model->last_error]);
        }
    }

    private function unenrolAction(): void
    {
        $model = $this->loadModel();
        if ($model->unenrol()) {
            Tools::log()->notice('unenrol-success');
        } else {
            Tools::log()->error('unenrol-failed', ['%error%' => $model->last_error]);
        }
    }

    private function suspendAction(): void
    {
        $model = $this->loadModel();
        if ($model->suspend()) {
            Tools::log()->notice('enrol-success');
        } else {
            Tools::log()->error('enrol-failed', ['%error%' => $model->last_error]);
        }
    }

    private function reactivateAction(): void
    {
        $model = $this->loadModel();
        if ($model->enrol()) {
            Tools::log()->notice('enrol-success');
        } else {
            Tools::log()->error('enrol-failed', ['%error%' => $model->last_error]);
        }
    }

    private function getMoodleUserIdAction(): void
    {
        $this->setTemplate(false);

        $idcontacto = (int)$this->request->request->get('idcontacto', 0);
        $idinstance = (int)$this->request->request->get('idinstance', 0);

        $result = ['moodle_userid' => 0];

        if ($idcontacto > 0 && $idinstance > 0) {
            $userMap = new MoodleUserMap();
            $where = [
                new DataBaseWhere('idcontacto', $idcontacto),
                new DataBaseWhere('idinstance', $idinstance),
            ];
            if ($userMap->loadFromCode('', $where)) {
                $result['moodle_userid'] = (int)$userMap->moodle_userid;
            }
        }

        $this->response->setContent(json_encode($result));
    }

    private function getMoodleCourseIdAction(): void
    {
        $this->setTemplate(false);

        $idcourseMap = (int)$this->request->request->get('idcourse_map', 0);

        $result = ['moodle_courseid' => 0, 'idinstance' => 0];

        if ($idcourseMap > 0) {
            $courseMap = new MoodleCourseMap();
            if ($courseMap->loadFromCode($idcourseMap)) {
                $result['moodle_courseid'] = (int)$courseMap->moodle_courseid;
                $result['idinstance'] = (int)$courseMap->idinstance;
            }
        }

        $this->response->setContent(json_encode($result));
    }
}
