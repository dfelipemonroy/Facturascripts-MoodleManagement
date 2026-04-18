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

use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\AttachedFileRelation;
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Model\Producto;
use FacturaScripts\Core\Model\ProductoImagen;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

class MoodleCourseMap extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idinstance;

    /** @var int */
    public $moodle_courseid;

    /** @var int */
    public $idproducto;

    /** @var string */
    public $shortname;

    /** @var string */
    public $fullname;

    /** @var string */
    public $summary;

    /** @var int */
    public $moodle_categoryid;

    /** @var string */
    public $format;

    /** @var int Unix timestamp */
    public $startdate;

    /** @var int Unix timestamp */
    public $enddate;

    /** @var bool */
    public $visible;

    /** @var int */
    public $enrolled_count;

    /** @var float */
    public $price;

    /** @var string */
    public $currency;

    /** @var bool */
    public $sync_active;

    /** @var string fs_managed / moodle_managed / synced */
    public $source;

    /** @var string */
    public $last_sync;

    /** @var string */
    public $last_error;

    /** @var int FK to attached_files for the Moodle cover image */
    public $idfile_cover;

    /** @var int Days of access after enrolment (0 = unlimited) */
    public $duracion_dias;

    /** @var string */
    public $creation_date;

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
        $this->format = 'topics';
        $this->startdate = 0;
        $this->enddate = 0;
        $this->visible = true;
        $this->sync_active = true;
        $this->enrolled_count = 0;
        $this->price = 0;
        $this->currency = 'EUR';
        $this->source = 'synced';
        $this->idfile_cover = null;
        $this->duracion_dias = 0;
        $this->creation_date = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_course_map';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'shortname';
    }

    public function test(): bool
    {
        if (empty($this->idinstance)) {
            Tools::log()->warning('instance-required');
            return false;
        }

        if (empty($this->shortname)) {
            Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'shortname']);
            return false;
        }

        $this->shortname = Tools::noHtml($this->shortname);
        $this->fullname = Tools::noHtml($this->fullname ?? '');
        $this->summary = Tools::noHtml($this->summary ?? '');
        $this->last_error = Tools::noHtml($this->last_error ?? '');

        return parent::test();
    }

    public function getInstance(): MoodleInstance
    {
        $instance = new MoodleInstance();
        $instance->loadFromCode($this->idinstance);
        return $instance;
    }

    public function getProducto(): Producto
    {
        $producto = new Producto();
        if (!empty($this->idproducto)) {
            $producto->loadFromCode($this->idproducto);
        }
        return $producto;
    }

    public function getCategory(): MoodleCourseCategory
    {
        $category = new MoodleCourseCategory();
        if (!empty($this->moodle_categoryid) && !empty($this->idinstance)) {
            $where = [
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idinstance', $this->idinstance),
                new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('moodle_categoryid', $this->moodle_categoryid),
            ];
            $category->loadFromCode('', $where);
        }
        return $category;
    }

    public function getStartDate(): string
    {
        return $this->startdate ? date('Y-m-d', $this->startdate) : '';
    }

    public function getEndDate(): string
    {
        return $this->enddate ? date('Y-m-d', $this->enddate) : '';
    }

    public function getCoverImage(): ?AttachedFile
    {
        if (empty($this->idfile_cover)) {
            return null;
        }
        $file = new AttachedFile();
        if ($file->loadFromCode($this->idfile_cover)) {
            return $file;
        }
        return null;
    }

    public function getCoverImageUrl(): string
    {
        $file = $this->getCoverImage();
        return $file ? $file->url('download-permanent') : '';
    }

    /**
     * Syncs the course price to the linked product's primary variant.
     * Only updates if the product has exactly 1 variant.
     */
    public function syncPriceToProduct(): void
    {
        if (empty($this->idproducto)) {
            return;
        }

        $producto = $this->getProducto();
        if (empty($producto->idproducto)) {
            return;
        }

        $variants = $producto->getVariants();
        if (count($variants) !== 1) {
            return;
        }

        $variant = $variants[0];
        if ((float)$variant->precio !== (float)$this->price) {
            $variant->precio = $this->price;
            $variant->save();
        }
    }

    /**
     * Updates the course price from the linked product's primary variant.
     * Only reads if the product has exactly 1 variant.
     */
    public function syncPriceFromProduct(): bool
    {
        if (empty($this->idproducto)) {
            return false;
        }

        $producto = $this->getProducto();
        if (empty($producto->idproducto)) {
            return false;
        }

        $variants = $producto->getVariants();
        if (count($variants) !== 1) {
            return false;
        }

        $variant = $variants[0];
        if ((float)$variant->precio !== (float)$this->price) {
            $this->price = $variant->precio;
            return true;
        }

        return false;
    }

    public function save(): bool
    {
        if (false === parent::save()) {
            return false;
        }

        $this->syncPriceToProduct();
        return true;
    }

    /**
     * Downloads the course overview image from Moodle and links it to the product.
     * Skips if idfile_cover already set (no duplicates).
     * @param array $overviewFiles The 'overviewfiles' array from Moodle course data
     * @param bool $force Force re-download even if cover already set
     */
    public function syncImageToProduct(array $overviewFiles, bool $force = false): bool
    {
        if (empty($this->idproducto) || empty($overviewFiles)) {
            return false;
        }

        // find the first image file
        $imageFile = null;
        foreach ($overviewFiles as $file) {
            if (
                !empty($file['fileurl']) && !empty($file['mimetype'])
                && strpos($file['mimetype'], 'image/') === 0
            ) {
                $imageFile = $file;
                break;
            }
        }

        if (null === $imageFile) {
            return false;
        }

        // skip if cover already set (unless forced)
        if (!$force && !empty($this->idfile_cover)) {
            $existing = new AttachedFile();
            if ($existing->loadFromCode($this->idfile_cover)) {
                return true;
            }
        }

        // download image from Moodle
        $instance = $this->getInstance();
        $localFilename = MoodleClient::downloadFile($instance, $imageFile['fileurl']);
        if (empty($localFilename)) {
            return false;
        }

        // create AttachedFile (FS moves/renames the file automatically)
        $attachedFile = new AttachedFile();
        $attachedFile->path = $localFilename;
        if (false === $attachedFile->save()) {
            return false;
        }

        // check if ProductoImagen already exists for this product+file
        $existingImg = new ProductoImagen();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idproducto', $this->idproducto),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('idfile', $attachedFile->idfile),
        ];
        if (false === $existingImg->loadFromCode('', $where)) {
            $productImage = new ProductoImagen();
            $productImage->idproducto = $this->idproducto;
            $productImage->idfile = $attachedFile->idfile;
            if (false === $productImage->save()) {
                return false;
            }

            $fileRelation = new AttachedFileRelation();
            $fileRelation->idfile = $attachedFile->idfile;
            $fileRelation->model = 'Producto';
            $fileRelation->modelid = $this->idproducto;
            $fileRelation->nick = null;
            $fileRelation->save();
        }

        // store the attached file ID as cover reference
        $this->idfile_cover = $attachedFile->idfile;
        return parent::save();
    }

    /**
     * Creates a Producto linked to this course map.
     * Returns true if product was created, false otherwise.
     */
    public function createLinkedProduct(): bool
    {
        if (!empty($this->idproducto)) {
            return false;
        }

        $producto = new Producto();
        $producto->referencia = 'MDL-' . ($this->moodle_courseid ?: $this->id);
        $producto->descripcion = $this->fullname;
        $producto->observaciones = $this->summary;
        $producto->precio = $this->price;
        $producto->nostock = true;
        $producto->sevende = true;
        $producto->secompra = false;
        $producto->ventasinstock = true;
        $producto->moodle_course = true;

        $category = $this->getCategory();
        if ($category->id && !empty($category->codfamilia)) {
            $producto->codfamilia = $category->codfamilia;
        }

        if (false === $producto->save()) {
            return false;
        }

        $this->idproducto = $producto->idproducto;
        return $this->save();
    }
}
