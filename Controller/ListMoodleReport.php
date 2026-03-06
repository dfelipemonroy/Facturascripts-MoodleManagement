<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;

class ListMoodleReport extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-reports';
        $data['icon'] = 'fa-solid fa-chart-bar';
        return $data;
    }

    protected function createViews()
    {
        $this->createUnbilledView();
        $this->createByStatusView();
        $this->createByCourseView();
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ListMoodleReport':
                $where = $view->where;
                $where[] = new DataBaseWhere('status', 'enrolled');
                $where[] = new DataBaseWhere('idfactura', null, 'IS');
                $view->loadData('', $where);
                break;

            case 'ListMoodleReportStatus':
                $view->loadData('', $view->where);
                break;

            case 'ListMoodleReportCourse':
                $view->loadData('', $view->where);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    private function createUnbilledView(): void
    {
        $this->addView('ListMoodleReport', 'MoodleEnrolment', 'unbilled-enrolments', 'fa-solid fa-file-invoice')
            ->addSearchFields(['moodle_userid', 'moodle_courseid', 'notes'])
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
            ->addOrderBy(['moodle_courseid'], 'course');

        $this->addFilterSelect('ListMoodleReport', 'idinstance', 'moodle-instance', 'idinstance', $this->getInstanceValues());
        $this->addFilterAutocomplete('ListMoodleReport', 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'descripcion');
    }

    private function createByStatusView(): void
    {
        $this->addView('ListMoodleReportStatus', 'MoodleEnrolment', 'enrolments-by-status', 'fa-solid fa-list-check')
            ->addSearchFields(['moodle_userid', 'moodle_courseid', 'notes'])
            ->addOrderBy(['status', 'enrolment_date'], 'status')
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2);

        $statuses = [
            ['code' => 'pending', 'description' => Tools::trans('pending')],
            ['code' => 'enrolled', 'description' => Tools::trans('enrolled')],
            ['code' => 'suspended', 'description' => Tools::trans('suspended')],
            ['code' => 'unenrolled', 'description' => Tools::trans('unenrolled')],
        ];
        $this->addFilterSelect('ListMoodleReportStatus', 'status', 'status', 'status', $statuses);
        $this->addFilterSelect('ListMoodleReportStatus', 'idinstance', 'moodle-instance', 'idinstance', $this->getInstanceValues());
    }

    private function createByCourseView(): void
    {
        $this->addView('ListMoodleReportCourse', 'MoodleEnrolment', 'enrolments-by-course', 'fa-solid fa-graduation-cap')
            ->addSearchFields(['moodle_userid', 'moodle_courseid', 'notes'])
            ->addOrderBy(['moodle_courseid', 'enrolment_date'], 'course')
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2);

        $this->addFilterAutocomplete('ListMoodleReportCourse', 'idcourse_map', 'moodle-course', 'idcourse_map', 'moodle_course_map', 'id', 'shortname');
        $this->addFilterSelect('ListMoodleReportCourse', 'idinstance', 'moodle-instance', 'idinstance', $this->getInstanceValues());

        $methods = [
            ['code' => 'manual', 'description' => Tools::trans('manual')],
            ['code' => 'self', 'description' => Tools::trans('self')],
            ['code' => 'fee', 'description' => Tools::trans('fee')],
            ['code' => 'cohort', 'description' => Tools::trans('cohort')],
            ['code' => 'meta', 'description' => Tools::trans('meta')],
        ];
        $this->addFilterSelect('ListMoodleReportCourse', 'enrolment_method', 'enrolment-method', 'enrolment_method', $methods);
    }

    private function getInstanceValues(): array
    {
        $instances = [];
        $model = new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance();
        foreach ($model->all([], [], 0, 0) as $instance) {
            $instances[] = ['code' => $instance->id, 'description' => $instance->name];
        }
        return $instances;
    }
}
