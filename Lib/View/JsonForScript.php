<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\View;

/**
 * Safe JSON encoder for values interpolated inside a `<script>` block.
 *
 * Addresses audit FE-01 and FE-02 (2026-04-17): the dashboard and
 * course-content templates used the built-in Twig `| json_encode | raw`
 * filter pipeline, which produces strings like `["</script>"]` that
 * break out of the surrounding `<script>` tag when the upstream Moodle
 * data contains literal `</script>`, single quotes, ampersands, or
 * other control characters.
 *
 * The HEX flags escape the four syntactic delimiters HTML parsers care
 * about into their Unicode escapes (`\u003C`, `\u0027`, `\u0022`,
 * `\u0026`), so the serialised payload can never close the tag or
 * smuggle an attribute context.
 *
 * Wraps `json_encode` with:
 *   - `JSON_HEX_TAG`  — `<` and `>` become `\u003C`, `\u003E`
 *   - `JSON_HEX_APOS` — `'` becomes `\u0027`
 *   - `JSON_HEX_QUOT` — `"` becomes `\u0022`
 *   - `JSON_HEX_AMP`  — `&` becomes `\u0026`
 *   - `JSON_UNESCAPED_UNICODE` — keep non-ASCII glyphs readable
 *   - `JSON_THROW_ON_ERROR`    — surface encoding failures
 *
 * @since 2.0 — FE-01 · FE-02
 */
final class JsonForScript
{
    public const FLAGS = JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_HEX_AMP
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    private function __construct()
    {
    }

    /**
     * Encodes the value for safe interpolation inside a `<script>`
     * block. Returns a valid JSON string; throws on encoding failure.
     *
     * @param mixed $value anything `json_encode` accepts.
     */
    public static function encode($value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
