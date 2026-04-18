<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\Familia;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\CodeModel;

class MoodleCourseCategory extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idinstance;

    /** @var int */
    public $moodle_categoryid;

    /** @var string */
    public $codfamilia;

    /** @var string */
    public $name;

    /** @var string */
    public $description;

    /** @var int */
    public $parent_categoryid;

    /** @var bool */
    public $visible;

    /** @var bool */
    public $sync_active;

    /** @var string fs_managed / moodle_managed / synced */
    public $source;

    /** @var string */
    public $last_sync;

    /** @var string */
    public $creation_date;

    public function clear(): void
    {
        parent::clear();
        $this->parent_categoryid = null;
        $this->visible = true;
        $this->sync_active = true;
        $this->source = 'synced';
        $this->creation_date = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_course_categories';
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
        $this->description = Tools::noHtml($this->description ?? '');

        return parent::test();
    }

    /**
     * Columns that {@see codeModelAll()} will accept as the
     * `$fieldCode` projection. Anything outside this allowlist
     * collapses back to the primary key.
     *
     * F5.6 — hardens the method against SQL injection, since the
     * parameter can be supplied by callers that forward user input
     * (CodeModel::all('field', ...) from autocomplete widgets).
     *
     * @since 2.0 F5.6 · §5.3
     * @var string[]
     */
    private const CODE_MODEL_ALLOWED_FIELDS = [
        'id',
        'idinstance',
        'moodle_categoryid',
        'codfamilia',
        'name',
        'parent_categoryid',
        'source',
    ];

    public function codeModelAll(string $fieldCode = ''): array
    {
        // F5.6 — SQL injection hardening: whitelist the column name
        // before splicing it into the query. Unknown / crafted
        // values silently fall back to the primary key.
        if ($fieldCode === '' || !in_array($fieldCode, self::CODE_MODEL_ALLOWED_FIELDS, true)) {
            $field = static::primaryColumn();
        } else {
            $field = $fieldCode;
        }
        // Extra belt-and-braces: only accept [a-z0-9_] identifiers.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field)) {
            $field = static::primaryColumn();
        }

        $results = [];
        $sql = 'SELECT DISTINCT ' . $field . ' AS code, '
            . "CONCAT(name, ' (', moodle_categoryid, ')') AS description "
            . 'FROM ' . static::tableName()
            . ' WHERE moodle_categoryid IS NOT NULL ORDER BY 2 ASC';
        $db = new DataBase();
        foreach ($db->selectLimit($sql, CodeModel::getLimit()) as $d) {
            $results[] = new CodeModel($d);
        }
        return $results;
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }

    public function getFamilia(): Familia
    {
        $familia = new Familia();
        if (!empty($this->codfamilia)) {
            $familia->loadFromCode($this->codfamilia);
        }
        return $familia;
    }

    public function getParent(): ?self
    {
        if (empty($this->parent_categoryid)) {
            return null;
        }

        $parent = new self();
        if ($parent->loadFromCode($this->parent_categoryid)) {
            return $parent;
        }
        return null;
    }

    public function getMoodleParentCategoryId(): int
    {
        $parent = $this->getParent();
        return $parent ? (int)$parent->moodle_categoryid : 0;
    }

    public static function findByMoodleCategoryId(int $idinstance, int $moodleCategoryId): ?self
    {
        $cat = new self();
        $where = [
            new DataBaseWhere('idinstance', $idinstance),
            new DataBaseWhere('moodle_categoryid', $moodleCategoryId),
        ];
        if ($cat->loadFromCode('', $where)) {
            return $cat;
        }
        return null;
    }
}
