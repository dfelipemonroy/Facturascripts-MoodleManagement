<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Widget;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Widget\BaseWidget;
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\ProductoImagen;
use FacturaScripts\Core\Tools;

class WidgetCourseimage extends BaseWidget
{
    /**
     * Renders the table cell with an image thumbnail.
     *
     * @param object $model The row model exposing the image FK in $this->fieldname.
     * @param string $display Alignment hint (kept for BaseWidget BC; not used).
     * @return string HTML <td>...</td> fragment.
     * @since 2.0 return type added
     */
    public function tableCell($model, $display = 'center'): string
    {
        $this->setValue($model);

        if (empty($this->value)) {
            return '<td class="text-center">-</td>';
        }

        $url = $this->getFileUrl((int)$this->value);
        if (empty($url)) {
            return '<td class="text-center">-</td>';
        }

        // Class `mm-thumb` is defined in Assets/CSS/moodle.css (F1.8).
        // The stylesheet must be loaded by the enclosing Twig.
        return '<td class="text-center">'
            . '<img loading="lazy" src="' . $url . '" class="mm-thumb" alt=""/>'
            . '</td>';
    }

    /**
     * Renders the edit form widget: image picker from product variant images.
     *
     * @param object $model     Row model.
     * @param string $title     Optional label shown above the picker.
     * @param string $description Unused (BaseWidget BC placeholder).
     * @param string $titleurl    Unused (BaseWidget BC placeholder).
     * @return string HTML fragment with radio-image picker.
     * @since 2.0 return type added
     */
    public function edit($model, $title = '', $description = '', $titleurl = ''): string
    {
        $this->setValue($model);

        $labelHtml = empty($title) ? '' : '<label class="mb-0">' . Tools::trans($title) . '</label>';

        // get product images if model has idproducto
        $idproducto = $model->idproducto ?? null;
        if (empty($idproducto)) {
            return '<div class="mb-3">' . $labelHtml
                . '<input type="hidden" name="' . $this->fieldname . '" value=""/>'
                . '<div class="mt-1 text-muted"><i class="fa-solid fa-image me-1"></i>'
                . Tools::trans('no-product-images') . '</div></div>';
        }

        $images = $this->getProductImages((int)$idproducto);
        if (empty($images)) {
            return '<div class="mb-3">' . $labelHtml
                . '<input type="hidden" name="' . $this->fieldname . '" value="' . ($this->value ?? '') . '"/>'
                . '<div class="mt-1 text-muted"><i class="fa-solid fa-image me-1"></i>'
                . Tools::trans('no-product-images') . '</div></div>';
        }

        // render image picker grid
        $html = '<div class="mb-3">' . $labelHtml;
        $html .= '<div class="d-flex flex-wrap gap-2 mt-1">';

        foreach ($images as $img) {
            $url = $this->getFileUrl($img->idfile);
            if (empty($url)) {
                continue;
            }

            $checked = ((int)$this->value === $img->idfile) ? ' checked' : '';
            $border = ((int)$this->value === $img->idfile) ? 'border-primary border-2' : 'border';
            $radioId = 'cover_' . $img->idfile;

            $html .= '<label for="' . $radioId . '" class="d-inline-block cursor-pointer" style="cursor:pointer;">'
                . '<input type="radio" name="' . $this->fieldname . '" id="' . $radioId . '"'
                . ' value="' . $img->idfile . '"' . $checked
                . ' class="d-none" onchange="this.closest(\'.d-flex\').querySelectorAll(\'.card\').forEach(c=>c.classList.remove(\'border-primary\',\'border-2\'));this.closest(\'label\').querySelector(\'.card\').classList.add(\'border-primary\',\'border-2\')"/>'
                . '<div class="card ' . $border . '" style="width:100px;height:100px;overflow:hidden;">'
                . '<img src="' . $url . '" class="w-100 h-100" style="object-fit:cover;" alt=""/>'
                . '</div>'
                . '</label>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Default textual representation when widget is rendered inline.
     *
     * @return string
     * @since 2.0 return type added
     */
    protected function show(): string
    {
        return is_null($this->value) ? '' : (string)$this->value;
    }

    private function getFileUrl(int $idfile): string
    {
        $file = new AttachedFile();
        if ($file->loadFromCode($idfile)) {
            return $file->url('download-permanent');
        }
        return '';
    }

    /**
     * @return ProductoImagen[]
     */
    private function getProductImages(int $idproducto): array
    {
        $img = new ProductoImagen();
        $where = [new DataBaseWhere('idproducto', $idproducto)];
        return $img->all($where, ['id' => 'ASC'], 0, 50);
    }
}
