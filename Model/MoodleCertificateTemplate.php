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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Configurable template for certificate PDF generation.
 * A template can be global (idinstance = null, is_default = true) or tied
 * to a specific Moodle instance.
 *
 * Lookup precedence in CertificatePdfGenerator:
 *   1. Template matching cert's idinstance (first row found)
 *   2. Global default template (idinstance IS NULL AND is_default = true)
 *   3. Hardcoded built-in defaults
 */
class MoodleCertificateTemplate extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string */
    public $name;

    /** @var int|null FK to moodle_instances.id; null = global template */
    public $idinstance;

    /** @var bool */
    public $is_default;

    /** @var string|null Main title of the certificate (defaults to translation key 'certificate-title') */
    public $title_text;

    /** @var string|null Subtitle shown below the title */
    public $subtitle_text;

    /** @var string|null Text shown below the student name ("has completed") */
    public $completed_text;

    /** @var string|null Footer verification text */
    public $verify_text;

    /** @var string|null Label above the issuer name/signature */
    public $issuer_label;

    /** @var string|null Hex color #RRGGBB for title/accents (dark blue) */
    public $primary_color;

    /** @var string|null Hex color #RRGGBB for borders/divider (gold) */
    public $accent_color;

    /** @var int */
    public $title_font_size;

    /** @var int */
    public $name_font_size;

    /** @var int */
    public $course_font_size;

    /** @var string|null Relative path from FS root to logo image */
    public $logo_path;

    /** @var bool */
    public $show_unique_hash;

    /** @var bool */
    public $show_date_issued;

    /** @var bool */
    public $show_issuer;

    /** @var string|null */
    public $notes;

    /** @var string */
    public $creation_date;

    public function clear(): void
    {
        parent::clear();
        $this->is_default = false;
        $this->title_font_size = 36;
        $this->name_font_size = 32;
        $this->course_font_size = 22;
        $this->show_unique_hash = true;
        $this->show_date_issued = true;
        $this->show_issuer = true;
        $this->primary_color = '#0054A1';
        $this->accent_color = '#D9B340';
        $this->creation_date = date('Y-m-d H:i:s');
    }

    public function install(): string
    {
        // ensure dependencies exist
        new MoodleInstance();
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
        return 'moodle_certificate_templates';
    }

    public function test(): bool
    {
        $this->name = Tools::noHtml($this->name);
        $this->title_text = Tools::noHtml($this->title_text);
        $this->subtitle_text = Tools::noHtml($this->subtitle_text);
        $this->completed_text = Tools::noHtml($this->completed_text);
        $this->verify_text = Tools::noHtml($this->verify_text);
        $this->issuer_label = Tools::noHtml($this->issuer_label);
        $this->logo_path = Tools::noHtml($this->logo_path);
        $this->notes = Tools::noHtml($this->notes);

        if (empty($this->name)) {
            Tools::log()->error('field-can-not-be-null', ['%fieldName%' => 'name']);
            return false;
        }

        if (!empty($this->primary_color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $this->primary_color)) {
            Tools::log()->error('invalid-hex-color', ['%fieldName%' => 'primary_color']);
            return false;
        }

        if (!empty($this->accent_color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $this->accent_color)) {
            Tools::log()->error('invalid-hex-color', ['%fieldName%' => 'accent_color']);
            return false;
        }

        // Clamp font sizes to sane values
        $this->title_font_size = max(10, min(72, (int)$this->title_font_size));
        $this->name_font_size = max(10, min(72, (int)$this->name_font_size));
        $this->course_font_size = max(8, min(48, (int)$this->course_font_size));

        return parent::test();
    }

    protected function saveInsert(array $values = []): bool
    {
        if ($this->is_default) {
            $this->unsetDefaults();
        }
        return parent::saveInsert($values);
    }

    protected function saveUpdate(array $values = []): bool
    {
        if ($this->is_default) {
            $this->unsetDefaults();
        }
        return parent::saveUpdate($values);
    }

    /**
     * If this template is marked as default, ensure no other template
     * within the same scope (global or instance) remains default.
     */
    private function unsetDefaults(): void
    {
        $where = [
            new DataBaseWhere('is_default', true),
            new DataBaseWhere('id', $this->id ?? 0, '!='),
        ];
        if (empty($this->idinstance)) {
            $where[] = new DataBaseWhere('idinstance', null, 'IS');
        } else {
            $where[] = new DataBaseWhere('idinstance', $this->idinstance);
        }

        foreach ($this->all($where) as $other) {
            $other->is_default = false;
            $other->save();
        }
    }

    /**
     * Finds the best-matching template for the given instance id.
     * Returns null when nothing is configured (caller should use hardcoded defaults).
     */
    public static function findBest(?int $idinstance): ?self
    {
        $tpl = new self();

        if (!empty($idinstance)) {
            $matches = $tpl->all([
                new DataBaseWhere('idinstance', $idinstance),
                new DataBaseWhere('is_default', true),
            ], [], 0, 1);
            if (!empty($matches)) {
                return $matches[0];
            }

            $matches = $tpl->all([new DataBaseWhere('idinstance', $idinstance)], [], 0, 1);
            if (!empty($matches)) {
                return $matches[0];
            }
        }

        $matches = $tpl->all([
            new DataBaseWhere('idinstance', null, 'IS'),
            new DataBaseWhere('is_default', true),
        ], [], 0, 1);
        if (!empty($matches)) {
            return $matches[0];
        }

        return null;
    }
}
