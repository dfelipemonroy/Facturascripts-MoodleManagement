<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * HTML sanitisation primitives for plugin-owned UI surfaces.
 *
 * Scope:
 *   - escape(): full entity escape for plain-text rendering.
 *   - escapeWithLineBreaks(): escape + convert \n to <br>, safe by
 *     construction because the escape happens first.
 *   - allowlist(): strict whitelist-based sanitiser for Moodle-sourced
 *     rich text (see F2.4 — CourseContent.html.twig).
 *
 * NOT a replacement for a full library like HTMLPurifier. Chosen to
 * avoid an external dependency for this plugin. Covers the attack
 * surface identified in the v2.0 audit:
 *   - Stored XSS in UserChat  (F2.1, F2.2)
 *   - Stored XSS in UserNotes (F2.3)
 *   - Stored XSS in CourseContent (F2.4)
 *
 * @since 2.0
 */
final class HtmlSanitizer
{
    /**
     * HTML tag allowlist for Moodle-sourced rich text. Anything else
     * is stripped. Keys are tag names; values are allowed attribute
     * names (pass [] to strip every attribute).
     *
     * `a` keeps only `href` which is further validated against
     * `http(s)://` or `mailto:` to block `javascript:` and data URIs.
     *
     * `img` keeps only `src` and `alt`; `src` is constrained to
     * `http(s)://` or `data:image/` (no javascript:).
     *
     * @var array<string, string[]>
     */
    private const TAG_ALLOWLIST = [
        'p'       => [],
        'br'      => [],
        'b'       => [],
        'strong'  => [],
        'i'       => [],
        'em'      => [],
        'u'       => [],
        'ul'      => [],
        'ol'      => [],
        'li'      => [],
        'h1'      => [],
        'h2'      => [],
        'h3'      => [],
        'h4'      => [],
        'h5'      => [],
        'h6'      => [],
        'pre'     => [],
        'code'    => [],
        'blockquote' => [],
        'a'       => ['href'],
        'img'     => ['src', 'alt'],
    ];

    /**
     * Entity-escape every HTML control character.
     *
     * @param string|null $text Untrusted input.
     * @return string           Safe for direct rendering inside an
     *                          HTML element body (NOT attribute).
     */
    public static function escape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape then turn \n into <br>. The output is safe to pass to
     * Twig `|raw` because the only tags present are the <br>
     * inserted here.
     *
     * @param string|null $text
     * @return string
     */
    public static function escapeWithLineBreaks(?string $text): string
    {
        $escaped = self::escape($text);
        return nl2br($escaped, false); // xhtml=false -> <br> not <br/>
    }

    /**
     * Allowlist-based HTML sanitiser for Moodle-sourced rich text.
     *
     * Algorithm:
     *   1. Parse with DOMDocument in HTML5-permissive mode.
     *   2. Walk the tree, remove any element not in TAG_ALLOWLIST.
     *   3. For each surviving element, strip attributes not in the
     *      per-tag list, and validate `href`/`src` schemes.
     *   4. Serialise back.
     *
     * If parsing fails (malformed input), we fall back to the
     * entity-escape strategy to guarantee no code reaches the page.
     *
     * @param string|null $html Untrusted HTML from Moodle.
     * @return string           Safe HTML fragment.
     */
    public static function allowlist(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Wrap in a <body> so DOMDocument parses fragments reliably.
        // Prefix with XML encoding declaration so UTF-8 is preserved.
        $wrapped = '<?xml encoding="UTF-8"?><body>' . $html . '</body>';
        $loaded = @$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Fallback: treat as plain text.
            return self::escapeWithLineBreaks($html);
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return self::escapeWithLineBreaks($html);
        }

        self::walkAndFilter($body);

        // Serialise children of <body> (without the wrapper itself)
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Recursively inspect $node, removing disallowed elements and
     * attributes in place.
     */
    private static function walkAndFilter(\DOMNode $node): void
    {
        // Iterate over a snapshot because we mutate during traversal.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!isset(self::TAG_ALLOWLIST[$tag])) {
                    // Not allowed: replace with its text content.
                    $text = $child->ownerDocument->createTextNode($child->textContent);
                    $node->replaceChild($text, $child);
                    continue;
                }

                // Strip disallowed attributes
                $allowedAttrs = self::TAG_ALLOWLIST[$tag];
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    if (!in_array($attr->nodeName, $allowedAttrs, true)) {
                        $attrsToRemove[] = $attr->nodeName;
                    }
                }
                foreach ($attrsToRemove as $name) {
                    $child->removeAttribute($name);
                }

                // URL scheme validation on href / src
                if ($child->hasAttribute('href') && !self::isSafeUrl($child->getAttribute('href'))) {
                    $child->removeAttribute('href');
                }
                if ($child->hasAttribute('src') && !self::isSafeImageSrc($child->getAttribute('src'))) {
                    $child->removeAttribute('src');
                }

                // Force rel="noopener noreferrer" on <a target=_blank>
                // (even though target is stripped by allowlist, defence in depth)
                if ($tag === 'a' && $child->hasAttribute('href')) {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }

                // Recurse
                self::walkAndFilter($child);
            } elseif ($child instanceof \DOMComment) {
                // Drop HTML comments (may carry conditional IE tricks).
                $node->removeChild($child);
            }
            // Text nodes are left as-is (DOMDocument handles encoding).
        }
    }

    /**
     * Accept http, https, mailto. Reject javascript:, data:, vbscript:, etc.
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // Relative URLs (starting with / or #) are acceptable.
        if ($url[0] === '/' || $url[0] === '#') {
            return true;
        }
        return (bool) preg_match('#^(https?://|mailto:)#i', $url);
    }

    /**
     * Accept http/https images and data:image/{png,jpeg,gif,webp,svg+xml}
     * — NOT svg+xml because SVG can carry <script>.
     */
    private static function isSafeImageSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        if ($src[0] === '/') {
            return true;
        }
        if (preg_match('#^https?://#i', $src)) {
            return true;
        }
        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $src)) {
            return true;
        }
        return false;
    }

    private function __construct()
    {
    }
}
