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
use FacturaScripts\Plugins\MoodleManagement\Lib\BadgeSyncHelper;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class ListMoodleCertificate extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-certificates';
        $data['icon'] = 'fa-solid fa-award';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleCertificate', 'MoodleCertificate', 'moodle-certificates', 'fa-solid fa-award')
            ->addSearchFields(['badge_name', 'course_name', 'unique_hash'])
            ->addOrderBy(['date_issued'], 'date-issued', 2)
            ->addOrderBy(['badge_name'], 'badge-name');

        $this->addFilterAutocomplete('ListMoodleCertificate', 'idcontacto', 'contact', 'idcontacto', 'contactos', 'idcontacto', 'descripcion');

        $instances = [];
        $model = new MoodleInstance();
        foreach ($model->all([], [], 0, 0) as $instance) {
            $instances[] = ['code' => $instance->id, 'description' => $instance->name];
        }
        $this->addFilterSelect('ListMoodleCertificate', 'idinstance', 'moodle-instance', 'idinstance', $instances);
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'sync-all-badges') {
            $this->syncAllBadgesAction();
            return true;
        }

        return parent::execPreviousAction($action);
    }

    private function syncAllBadgesAction(): void
    {
        $instanceModel = new MoodleInstance();
        $instances = $instanceModel->all(
            [new DataBaseWhere('status', 'active')],
            [],
            0,
            0
        );

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($instances as $instance) {
            if (empty($instance->token)) {
                continue;
            }

            $mapModel = new MoodleUserMap();
            $maps = $mapModel->all(
                [
                    new DataBaseWhere('idinstance', $instance->id),
                    new DataBaseWhere('moodle_userid', 0, '>'),
                ],
                [],
                0,
                0
            );

            foreach ($maps as $map) {
                $synced = BadgeSyncHelper::syncUserBadges($instance, $map->moodle_userid, $map->idcontacto);
                if ($synced < 0) {
                    $totalErrors++;
                } else {
                    $totalSynced += $synced;
                }
            }
        }

        if ($totalErrors > 0) {
            Tools::log()->warning('sync-completed', [
                '%synced%' => $totalSynced,
                '%errors%' => $totalErrors,
            ]);
        } else {
            Tools::log()->notice('badges-synced', ['%count%' => $totalSynced]);
        }
    }
}
