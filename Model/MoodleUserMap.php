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
use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Model\SoftDeleteTrait;

class MoodleUserMap extends ModelClass
{
    use ModelTrait;
    use SoftDeleteTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idcontacto;

    /** @var int */
    public $idinstance;

    /** @var int */
    public $moodle_userid;

    /** @var string */
    public $moodle_username;

    /** @var string */
    public $sync_direction;

    /** @var string */
    public $last_sync;

    /** @var string */
    public $last_error;

    /** @var string newest_wins, fs_wins, moodle_wins — overrides instance default if set */
    public $sync_priority;

    /** @var string */
    public $creation_date;

    /**
     * @var string|null ISO timestamp; non-null marks the row as
     *                  soft-deleted (F5.20 column + F13 DISCOVERED-02
     *                  trait wiring). The papelera (F10.4) surfaces
     *                  these rows for restore or purge.
     * @since 2.0 — F13 DISCOVERED-02
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
        $this->sync_direction = 'bidirectional';
        $this->sync_priority = 'newest_wins';
        $this->creation_date = date('Y-m-d H:i:s');
    }

    /**
     * Get effective sync priority (per-mapping override or instance default).
     */
    public function getEffectivePriority(): string
    {
        if (!empty($this->sync_priority)) {
            return $this->sync_priority;
        }
        $instance = $this->getInstance();
        return $instance->default_sync_priority ?: 'newest_wins';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_user_map';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'moodle_username';
    }

    public function test(): bool
    {
        if (empty($this->idcontacto)) {
            Tools::log()->warning('contact-required');
            return false;
        }

        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }

        $this->moodle_userid = (int)$this->moodle_userid;
        $this->moodle_username = Tools::noHtml($this->moodle_username ?? '');
        $this->last_error = Tools::noHtml($this->last_error ?? '');

        return parent::test();
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }

    public function getContacto(): Contacto
    {
        $contacto = new Contacto();
        $contacto->loadFromCode($this->idcontacto);
        return $contacto;
    }
}
