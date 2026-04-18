<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

class MoodleRoleMap extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idinstance;

    /** @var int Moodle role ID (1-8 standard) */
    public $moodle_roleid;

    /** @var string */
    public $moodle_role_shortname;

    /** @var string Translated role name */
    public $description;

    /** @var bool If true, this is the default role for the instance */
    public $is_default;

    /** @var string course|system */
    public $context_level;

    /** @var string */
    public $creation_date;

    /**
     * @var string|null F5.22 — insert timestamp.
     */
    public $created_at;

    /**
     * @var string|null F5.22 — last-touch timestamp.
     */
    public $updated_at;

    public function clear(): void
    {
        parent::clear();
        $this->moodle_roleid = 5;
        $this->moodle_role_shortname = 'student';
        $this->is_default = false;
        $this->context_level = 'course';
        $this->creation_date = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_role_map';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'moodle_role_shortname';
    }

    public function test(): bool
    {
        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }

        if (empty($this->moodle_roleid) || $this->moodle_roleid < 1) {
            Tools::log()->warning('roleid-required');
            return false;
        }

        $this->moodle_roleid = (int)$this->moodle_roleid;
        $this->moodle_role_shortname = Tools::noHtml($this->moodle_role_shortname ?? '');
        $this->description = Tools::noHtml($this->description ?? '');

        return parent::test();
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }

    /**
     * Resolve the Moodle role ID for a contact in a given instance.
     * Returns the default role for the instance, or 5 (student).
     */
    public static function resolveRoleForContact(int $idinstance, int $idcontacto): int
    {
        return self::getDefaultRole($idinstance);
    }

    /**
     * Get the default role for an instance (where is_default=true),
     * or 5 (student) if none configured.
     */
    public static function getDefaultRole(int $idinstance): int
    {
        $roleMap = new self();
        $where = [
            new DataBaseWhere('idinstance', $idinstance),
            new DataBaseWhere('is_default', true),
        ];
        if ($roleMap->loadFromCode('', $where)) {
            return (int)$roleMap->moodle_roleid;
        }

        return 5;
    }

    /**
     * Standard Moodle roles with their IDs and shortnames.
     */
    public static function getStandardRoles(): array
    {
        return [
            1 => 'manager',
            2 => 'coursecreator',
            3 => 'editingteacher',
            4 => 'teacher',
            5 => 'student',
            6 => 'guest',
            7 => 'user',
            8 => 'frontpage',
        ];
    }
}
