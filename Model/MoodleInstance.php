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

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Model\Base\CompanyRelationTrait;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

class MoodleInstance extends ModelClass
{
    use ModelTrait;
    use CompanyRelationTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $name;

    /** @var string */
    public $url;

    /** @var string */
    public $token;

    /** @var string */
    public $status;

    /** @var string */
    public $environment;

    /** @var string */
    public $moodle_version;

    /** @var string */
    public $moodle_release;

    /** @var string */
    public $site_name;

    /** @var string */
    public $lang;

    /** @var string */
    public $service_username;

    /** @var int */
    public $service_userid;

    /** @var int */
    public $available_functions;

    /** @var string */
    public $last_check;

    /** @var string */
    public $last_error;

    /** @var string JSON: maps Moodle custom field shortnames to FS contact fields */
    public $custom_fields_map;

    /** @var string default sync priority: newest_wins, fs_wins, moodle_wins */
    public $default_sync_priority;

    /** @var string */
    public $notes;

    /** @var string */
    public $creation_date;

    public function clear(): void
    {
        parent::clear();
        $this->status = 'active';
        $this->environment = 'production';
        $this->default_sync_priority = 'newest_wins';
        $this->creation_date = date('Y-m-d H:i:s');
    }

    /**
     * Get custom fields map as associative array.
     * Format: ['moodle_shortname' => 'fs_field_name']
     */
    public function getCustomFieldsMap(): array
    {
        if (empty($this->custom_fields_map)) {
            return [];
        }
        $map = json_decode($this->custom_fields_map, true);
        return is_array($map) ? $map : [];
    }

    public function install(): string
    {
        new \FacturaScripts\Core\Model\Empresa();
        return parent::install();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'name';
    }

    public static function tableName(): string
    {
        return 'moodle_instances';
    }

    public function test(): bool
    {
        $this->name = Tools::noHtml($this->name);
        $this->url = Tools::noHtml($this->url);
        $this->notes = Tools::noHtml($this->notes);

        if (empty($this->name)) {
            Tools::log()->error('field-can-not-be-null', ['%fieldName%' => 'name']);
            return false;
        }

        if (empty($this->url)) {
            Tools::log()->error('field-can-not-be-null', ['%fieldName%' => 'url']);
            return false;
        }

        if (!filter_var($this->url, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//', $this->url)) {
            Tools::log()->error('invalid-moodle-url');
            return false;
        }

        // Remove trailing slash from URL
        $this->url = rtrim($this->url, '/');

        return parent::test();
    }
}
