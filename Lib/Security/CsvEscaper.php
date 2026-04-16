<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * Escapes cell values for CSV output so they cannot be interpreted
 * as formulas by Excel / LibreOffice Calc / Google Sheets.
 *
 * Formula injection (aka "CSV injection" / OWASP 2022-A03) happens
 * when an untrusted string starts with one of the characters that
 * a spreadsheet app interprets as a formula prefix: `=`, `+`, `-`,
 * `@`, TAB (`\t`), CR (`\r`). Opening the CSV then runs code like
 * `=cmd|'/c calc'!A0` or exfiltrates data via `=HYPERLINK(...)`.
 *
 * Mitigation (per OWASP): prefix a single quote `'` to neutralise
 * the cell content. The single quote is consumed by the spreadsheet
 * and the cell is rendered as plain text.
 *
 * This class is intentionally tiny and allocation-free so it can be
 * called per-cell without impacting export throughput.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F2.7 · §3.4
 */
final class CsvEscaper
{
    /**
     * Characters that, when they appear as the first byte of a cell,
     * make the spreadsheet treat the rest as a formula.
     *
     * `'\t'` and `'\r'` are treated as dangerous because many CSV
     * parsers strip leading whitespace and then interpret the next
     * char, which would bypass a naive filter.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Returns a CSV-safe version of a single cell.
     *
     * Non-string inputs (int, float, bool, null) are returned as-is
     * converted to string — they cannot start with one of the
     * dangerous prefixes from a user-controlled path.
     *
     * @param mixed $value Cell value of any scalar type.
     * @return string      Escaped string ready to be written.
     */
    public static function escape($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        if (in_array($str[0], self::DANGEROUS_PREFIXES, true)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Map self::escape() over every value of an associative or
     * indexed array. Keys are left untouched (they become CSV
     * headers, still rendered by the caller).
     *
     * @param array<int|string, mixed> $row
     * @return array<int|string, string>
     */
    public static function escapeRow(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = self::escape($v);
        }
        return $out;
    }

    /**
     * Helper for PHP's fputcsv-based exports — feeds it an already
     * sanitised row so the caller only has to worry about order.
     *
     * @param resource $handle Open file/stream.
     * @param array<int|string, mixed> $row
     * @param string $separator
     * @param string $enclosure
     * @param string $escape
     */
    public static function fputcsvSafe(
        $handle,
        array $row,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\'
    ): int {
        $clean = self::escapeRow($row);
        $written = @fputcsv($handle, $clean, $separator, $enclosure, $escape);
        return $written === false ? 0 : (int) $written;
    }

    private function __construct()
    {
    }
}
