<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * @since 2.0 — Part of the configurable certificate templates feature
 *              (see .docs-dev/BRAINSTORMING.md §v2.0-E).
 */
class ListMoodleCertificateTemplate extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'certificate-templates';
        $data['icon'] = 'fa-solid fa-award';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleCertificateTemplate', 'MoodleCertificateTemplate', 'certificate-templates', 'fa-solid fa-award')
            ->addSearchFields(['name', 'title_text', 'subtitle_text'])
            ->addOrderBy(['name'], 'name', 1)
            ->addOrderBy(['is_default'], 'is-default')
            ->addOrderBy(['creation_date'], 'creation-date');

        $this->addFilterCheckbox('ListMoodleCertificateTemplate', 'is_default', 'is-default', 'is_default');
        $this->addFilterAutocomplete(
            'ListMoodleCertificateTemplate',
            'idinstance',
            'moodle-instance',
            'idinstance',
            'moodle_instances',
            'id',
            'name'
        );
    }
}
