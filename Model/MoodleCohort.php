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

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Model\SoftDeleteTrait;

class MoodleCohort extends ModelClass
{
    use ModelTrait;
    use SoftDeleteTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idinstance;

    /** @var int */
    public $moodle_cohortid;

    /** @var string */
    public $name;

    /** @var string */
    public $idnumber;

    /** @var string */
    public $description;

    /** @var string fs_managed / moodle_managed / synced */
    public $source;

    /** @var int */
    public $member_count;

    /** @var string FK to gruposclientes.codgrupo */
    public $codgrupo;

    /** @var bool */
    public $sync_active;

    /** @var string */
    public $last_sync;

    /** @var string */
    public $creation_date;

    /**
     * @var string|null Soft-delete marker (F5.20 + F13 DISCOVERED-02).
     *                  Non-null means the row is in the papelera.
     * @since 2.0
     */
    public $deleted_at;

    /**
     * @var string|null Operator nick that originally inserted the row.
     * Populated by FS core's audit-trail layer (F5.15).
     * Declared as a real property to avoid PHP 8.2 dynamic-property
     * deprecation warnings.
     */
    public $created_by;

    /**
     * @var string|null Operator nick that last touched the row. Same
     * provenance as `$created_by`.
     */
    public $updated_by;

    public function clear(): void
    {
        parent::clear();
        $this->source = 'synced';
        $this->sync_active = false;
        $this->member_count = 0;
        $this->creation_date = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_cohorts';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'name';
    }

    public function test(): bool
    {
        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }

        if (empty($this->name)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'name']);
            return false;
        }

        $this->name = Tools::noHtml($this->name);
        $this->idnumber = Tools::noHtml($this->idnumber ?? '');
        $this->description = Tools::noHtml($this->description ?? '');

        return parent::test();
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }
}
