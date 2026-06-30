<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Model\SoftDeleteTrait;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

class MoodleEnrolment extends ModelClass
{
    use ModelTrait;
    use SoftDeleteTrait;

    /** @var int */

    public $id;
    /** @var int */
    public $idinstance;
    /** @var int */
    public $idcontacto;
    /** @var int */
    public $moodle_userid;
    /** @var int */
    public $moodle_courseid;
    /** @var int|null */
    public $idcourse_map;
    /** @var int|null */
    public $idfactura;
    /** @var int|null */
    public $idpedido;
    /** @var int|null */
    public $idpresupuesto;
    /** @var int */
    public $roleid;
    /** @var string pending|enrolled|suspended|unenrolled */
    public $status;
    /** @var string manual|self|fee|cohort|meta */
    public $enrolment_method;
    /** @var int */
    public $timestart;
    /** @var int */
    public $timeend;
    /** @var string|null */
    public $enrolment_key;
    /** @var string */
    public $enrolment_date;
    /** @var string|null */
    public $last_sync;
    /** @var string|null */
    public $last_error;
    /** @var string|null */
    public $notes;
    /**
     * @var int|null 0..100 activity completion percentage, refreshed
     *               by the F10.2 progress-sync cron.
     * @since 2.0 — F10.2
     */
    public $progress_percent;
    /** @var int|null @since 2.0 — F10.2 */
    public $completed_modules;
    /** @var int|null @since 2.0 — F10.2 */
    public $total_modules;
    /** @var string|null @since 2.0 — F10.2 latest module completion timestamp */
    public $last_activity_at;
    /** @var string|null @since 2.0 — F10.2 when the last WS probe ran */
    public $progress_fetched_at;
    /** @var string|null @since 2.0 — F10.1 / F10.2 course completion time */
    public $completion_date;
    /** @var float|null @since 2.0 — F10.2 latest grade (0..100) */
    public $final_grade;
    /**
     * @var string|null Soft-delete marker (F5.20 + F13 DISCOVERED-02).
     *                  Non-null means the row is in the papelera.
     * @since 2.0
     */
    public $deleted_at;
    /**
     * @var string|null Operator nick that originally inserted the row.
     *                  Populated by FS core's audit-trail layer (F5.15).
     *                  Declared as a real property to avoid PHP 8.2 dynamic-property
     *                  deprecation warnings.
     */
    public $created_by;

    /**
     * @var string|null Operator nick that last touched the row. Same
     *                  provenance as `$created_by`.
     */
    public $updated_by;

    /**
     * @var string|null F5.5 — original idfactura preserved after fiscal archive.
     */
    public $idfactura_archived;

    public function clear(): void
    {
        parent::clear();
        $this->roleid = 5;
        $this->status = 'pending';
        $this->enrolment_method = 'manual';
        $this->timestart = 0;
        $this->timeend = 0;
        $this->enrolment_date = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_enrolments';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'id';
    }

    public function test(): bool
    {
        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }
        if (empty($this->idcontacto)) {
            Tools::log()->warning('contact-required');
            return false;
        }
        if (empty($this->moodle_userid)) {
            Tools::log()->warning('moodle-userid-required');
            return false;
        }
        if (empty($this->moodle_courseid)) {
            Tools::log()->warning('moodle-courseid-required');
            return false;
        }

        // convert 0 to null for nullable FK fields
        $this->idcourse_map = empty($this->idcourse_map) ? null : (int)$this->idcourse_map;
        $this->idfactura = empty($this->idfactura) ? null : (int)$this->idfactura;
        $this->idpedido = empty($this->idpedido) ? null : (int)$this->idpedido;
        $this->idpresupuesto = empty($this->idpresupuesto) ? null : (int)$this->idpresupuesto;
        $this->last_error = Tools::noHtml($this->last_error ?? '');
        $this->notes = Tools::noHtml($this->notes ?? '');
        $this->enrolment_key = Tools::noHtml($this->enrolment_key ?? '');
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

    public function getCourseMap(): ?MoodleCourseMap
    {
        if (empty($this->idcourse_map)) {
            return null;
        }
        $map = new MoodleCourseMap();
        return $map->loadFromCode($this->idcourse_map) ? $map : null;
    }

    public function getUserMap(): ?MoodleUserMap
    {
        $userMap = new MoodleUserMap();
        $where = [
            new DataBaseWhere('idcontacto', $this->idcontacto),
            new DataBaseWhere('idinstance', $this->idinstance),
        ];
        return $userMap->loadFromCode('', $where) ? $userMap : null;
    }

    public function isActive(): bool
    {
        return $this->status === 'enrolled';
    }

    /**
     * Enrol this user in Moodle. Updates status on success.
     */
    public function enrol(): bool
    {
        $instance = $this->getInstance();
        $result = MoodleClient::enrolUsers($instance, [[
            'userid' => $this->moodle_userid,
            'courseid' => $this->moodle_courseid,
            'roleid' => $this->roleid,
            'timestart' => $this->timestart ?: 0,
            'timeend' => $this->timeend ?: 0,
            'suspend' => 0,
        ]]);
        if (isset($result['exception'])) {
            $this->last_error = $result['message'] ?? $result['exception'];
            $this->last_sync = date('Y-m-d H:i:s');
            $this->save();
            return false;
        }

        $this->status = 'enrolled';
        $this->last_sync = date('Y-m-d H:i:s');
        $this->last_error = '';
        return $this->save();
    }

    /**
     * Unenrol this user from Moodle. Updates status on success.
     */
    public function unenrol(): bool
    {
        $instance = $this->getInstance();
        $result = MoodleClient::unenrolUsers($instance, [[
            'userid' => $this->moodle_userid,
            'courseid' => $this->moodle_courseid,
        ]]);
        if (isset($result['exception'])) {
            $this->last_error = $result['message'] ?? $result['exception'];
            $this->last_sync = date('Y-m-d H:i:s');
            $this->save();
            return false;
        }

        $this->status = 'unenrolled';
        $this->last_sync = date('Y-m-d H:i:s');
        $this->last_error = '';
        return $this->save();
    }

    /**
     * Suspend this enrolment in Moodle.
     */
    public function suspend(): bool
    {
        $instance = $this->getInstance();
        $result = MoodleClient::enrolUsers($instance, [[
            'userid' => $this->moodle_userid,
            'courseid' => $this->moodle_courseid,
            'roleid' => $this->roleid,
            'suspend' => 1,
        ]]);
        if (isset($result['exception'])) {
            $this->last_error = $result['message'] ?? $result['exception'];
            $this->last_sync = date('Y-m-d H:i:s');
            $this->save();
            return false;
        }

        $this->status = 'suspended';
        $this->last_sync = date('Y-m-d H:i:s');
        $this->last_error = '';
        return $this->save();
    }

    /**
     * Reactivate a suspended enrolment.
     */
    public function reactivate(): bool
    {
        return $this->enrol();
    }

    public function url(string $type = 'auto', string $list = 'List'): string
    {
        return parent::url($type, $list);
    }
}
