<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

class EditMoodleCertificate extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleCertificate';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-certificate';
        $data['icon'] = 'fa-solid fa-award';
        return $data;
    }
}
