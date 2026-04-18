<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Widget;

use FacturaScripts\Core\Lib\Widget\BaseWidget;

/**
 * Renders a Moodle Unix-epoch timestamp (INT column) as a human
 * readable `Y-m-d H:i` string.
 *
 * Moodle stores `timestart`, `timeend`, `timecreated`, etc. as
 * INT UNSIGNED seconds since the epoch. FacturaScripts' default
 * `number` widget prints the raw integer which is meaningless to
 * operators; the `datetime` widget only handles SQL DATETIME
 * columns.
 *
 * Usage in XMLView:
 *   <widget type="moodleTimestamp" fieldname="timestart" />
 *
 * Register via:
 *   \FacturaScripts\Core\Lib\Widget\BaseWidget::addExtension(
 *       'moodleTimestamp',
 *       WidgetMoodleTimestamp::class
 *   );
 *
 * An empty / zero value renders as an em dash so lists stay tidy.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F3.7 · §3.10
 */
class WidgetMoodleTimestamp extends BaseWidget
{
    /** Format used in every render path. */
    private const FORMAT = 'Y-m-d H:i';

    /** Rendered when $value is null / 0 / empty. */
    private const EMPTY_PLACEHOLDER = '-';

    /**
     * Table cell output.
     *
     * @param object $model
     * @param string $display
     * @return string
     */
    public function tableCell($model, $display = 'center'): string
    {
        $this->setValue($model);
        $human = self::format($this->value);
        return '<td class="text-' . htmlspecialchars((string)$display, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '">'
            . htmlspecialchars($human, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            . '</td>';
    }

    /**
     * Edit form output. The epoch value stays in a hidden input so
     * the integer persists on submit, while a disabled text input
     * shows the human-readable date.
     *
     * @param object $model
     * @param string $title
     * @param string $description
     * @param string $titleurl
     * @return string
     */
    public function edit($model, $title = '', $description = '', $titleurl = ''): string
    {
        $this->setValue($model);
        $human = self::format($this->value);

        $labelTitle = empty($title) ? $this->fieldname : $title;
        $rawValue = (int) ($this->value ?? 0);

        return '<div class="mb-3">'
            . '<label class="form-label mb-0">'
            . htmlspecialchars($labelTitle, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            . '</label>'
            . '<input type="text" class="form-control" readonly="readonly" value="'
            . htmlspecialchars($human, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            . '"/>'
            . '<input type="hidden" name="' . htmlspecialchars($this->fieldname, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '" value="'
            . $rawValue
            . '"/>'
            . '</div>';
    }

    /**
     * Inline-text representation (used when the widget appears
     * outside a table row).
     *
     * @return string
     */
    protected function show(): string
    {
        return self::format($this->value);
    }

    /**
     * Safe formatter used by every render path. Returns a
     * placeholder instead of throwing so broken data never breaks
     * a list view.
     *
     * @param mixed $value
     * @return string
     */
    public static function format($value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return self::EMPTY_PLACEHOLDER;
        }
        $epoch = (int) $value;
        if ($epoch <= 0) {
            return self::EMPTY_PLACEHOLDER;
        }
        // Epoch values older than 2001-09-09 (a billion seconds) are
        // treated as junk data; showing "1970-01-01" would be worse
        // than a placeholder.
        if ($epoch < 1000000000) {
            return self::EMPTY_PLACEHOLDER;
        }
        return date(self::FORMAT, $epoch);
    }
}
