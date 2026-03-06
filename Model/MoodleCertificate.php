<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

class MoodleCertificate extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idinstance;

    /** @var int */
    public $idcontacto;

    /** @var int */
    public $moodle_userid;

    /** @var int */
    public $badge_id;

    /** @var string */
    public $badge_name;

    /** @var string */
    public $description;

    /** @var int */
    public $moodle_courseid;

    /** @var string */
    public $course_name;

    /** @var string */
    public $date_issued;

    /** @var string */
    public $date_expire;

    /** @var string */
    public $unique_hash;

    /** @var string */
    public $badge_url;

    /** @var string */
    public $image_url;

    /** @var int */
    public $idfactura;

    /** @var string */
    public $last_sync;

    public function clear(): void
    {
        parent::clear();
        $this->last_sync = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_certificates';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'badge_name';
    }

    public function test(): bool
    {
        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }

        if (empty($this->badge_id)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'badge_id']);
            return false;
        }

        if (empty($this->badge_name)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'badge_name']);
            return false;
        }

        $this->badge_name = Tools::noHtml($this->badge_name);
        $this->description = Tools::noHtml($this->description ?? '');
        $this->course_name = Tools::noHtml($this->course_name ?? '');
        $this->unique_hash = Tools::noHtml($this->unique_hash ?? '');

        return parent::test();
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }

}
