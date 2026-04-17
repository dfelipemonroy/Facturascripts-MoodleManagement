<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Moodle-side logic for the Producto edit flow. Moved out of the
 * `Extension/Controller/EditProducto` closure so that:
 *
 *   1. The extension only uses FS-sanctioned public APIs
 *      ($this->getViewModelValue, $this->setSettings,
 *      $this->addListView). It no longer touches
 *      `$this->views[$mainView]->model`, which is a protected
 *      property on BaseController — reaching into it broke
 *      encapsulation and could collide with other plugins (e.g.
 *      StockAvanzado) that extend the same view.
 *
 *   2. The actual CourseMap upsert is a pure function of the
 *      handful of product fields we care about, so it becomes
 *      independently testable (covered by Fase 9).
 *
 *   3. Any future "auto-create course map on product save" policy
 *      change happens here instead of inside a closure.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.3 · §1.3
 */
final class ProductoMoodleDecorator
{
    /**
     * Payload shape passed by the Extension to this helper.
     *
     * @var array{
     *   idproducto: int,
     *   is_course:  bool,
     *   referencia: string,
     *   descripcion: string,
     *   observaciones: string,
     *   precio: float
     * }
     */

    /**
     * Upsert the MoodleCourseMap linked to a FacturaScripts product.
     *
     * - If `is_course` is false, returns immediately.
     * - If a CourseMap already exists, just syncs the price.
     * - Otherwise creates a new "fs_managed" course map on the first
     *   active Moodle instance (operator later promotes it to
     *   synced=true via the course-map edit screen).
     *
     * @param array $data Pure data extracted from the view model.
     */
    public static function syncCourseMap(array $data): void
    {
        $idproducto = (int) ($data['idproducto'] ?? 0);
        if ($idproducto <= 0 || empty($data['is_course'])) {
            return;
        }

        $existing = new MoodleCourseMap();
        $where = [new DataBaseWhere('idproducto', $idproducto)];
        if ($existing->loadFromCode('', $where)) {
            if ($existing->syncPriceFromProduct()) {
                $existing->save();
            }
            return;
        }

        $instance = new MoodleInstance();
        $whereInstance = [new DataBaseWhere('status', 'active')];
        if (false === $instance->loadFromCode('', $whereInstance)) {
            Tools::log()->warning('no-active-moodle-instance');
            return;
        }

        $map = new MoodleCourseMap();
        $map->idinstance = $instance->id;
        $map->idproducto = $idproducto;
        $map->shortname = (string) ($data['referencia'] ?? '');
        $map->fullname = (string) ($data['descripcion'] ?? '');
        $map->summary = (string) ($data['observaciones'] ?? '');
        $map->price = (float) ($data['precio'] ?? 0);
        $map->sync_active = false;
        $map->source = 'fs_managed';
        $map->save();
    }

    private function __construct()
    {
    }
}
