<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;

class ListMoodleEnrolment extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-enrolments';
        $data['icon'] = 'fa-solid fa-user-graduate';
        return $data;
    }

    protected function createViews(): void
    {
        $this->addView('ListMoodleEnrolment', 'MoodleEnrolment', 'moodle-enrolments', 'fa-solid fa-user-graduate')
            ->addSearchFields(['moodle_userid', 'moodle_courseid', 'notes'])
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
            ->addOrderBy(['status'], 'status')
            ->addOrderBy(['moodle_courseid'], 'course')
            ->addOrderBy(['last_sync'], 'last-sync');

        $statuses = [
            ['code' => 'pending', 'description' => Tools::trans('pending')],
            ['code' => 'enrolled', 'description' => Tools::trans('enrolled')],
            ['code' => 'suspended', 'description' => Tools::trans('suspended')],
            ['code' => 'unenrolled', 'description' => Tools::trans('unenrolled')],
        ];
        $this->addFilterSelect('ListMoodleEnrolment', 'status', 'status', 'status', $statuses);

        $methods = [
            ['code' => 'manual', 'description' => Tools::trans('manual')],
            ['code' => 'self', 'description' => Tools::trans('self')],
            ['code' => 'fee', 'description' => Tools::trans('fee')],
            ['code' => 'cohort', 'description' => Tools::trans('cohort')],
            ['code' => 'meta', 'description' => Tools::trans('meta')],
        ];
        $this->addFilterSelect('ListMoodleEnrolment', 'enrolment_method', 'enrolment-method', 'enrolment_method', $methods);

        $this->addFilterAutocomplete('ListMoodleEnrolment', 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'descripcion');

        $this->addFilterSelect('ListMoodleEnrolment', 'idinstance', 'moodle-instance', 'idinstance', $this->getInstanceValues());

        // F13 DISCOVERED-02 — hide soft-deleted rows by default.
        $this->addFilterSelectWhere('ListMoodleEnrolment', 'state', [
            [
                'label' => Tools::lang()->trans('active'),
                'where' => [new DataBaseWhere('deleted_at', null, 'IS')],
                'default' => true,
            ],
            [
                'label' => Tools::lang()->trans('all'),
                'where' => [],
            ],
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'enrol-batch':
                $this->enrolBatchAction();
                return true;

            case 'unenrol-batch':
                $this->unenrolBatchAction();
                return true;

            case 'suspend-batch':
                $this->suspendBatchAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function enrolBatchAction(): void
    {
        $this->processBatch('enrol');
    }

    private function unenrolBatchAction(): void
    {
        $this->processBatch('unenrol');
    }

    private function suspendBatchAction(): void
    {
        $this->processBatch('suspend');
    }

    private function processBatch(string $action): void
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

            $result = false;
            switch ($action) {
                case 'enrol':
                    $result = $model->enrol();
                    break;
                case 'unenrol':
                    $result = $model->unenrol();
                    break;
                case 'suspend':
                    $result = $model->suspend();
                    break;
            }

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
