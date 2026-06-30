<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * Builds a Content-Security-Policy header tuned to the controllers
 * shipped by this plugin.
 *
 * Controllers invoke CspHeader::apply($this->response) at the top
 * of their private core so the header is emitted regardless of how
 * the response body is built. The policy is deliberately written
 * as a per-plugin layer: it sits on top of whatever the FS core
 * emits (which is a broader baseline).
 *
 * Constraints driving the current policy:
 *   - jQuery inline event handlers are still in use in several
 *     Twigs (onclick, onsubmit, onkeydown). Fase 3 F3.2 removes
 *     them; until then `'unsafe-inline'` in script-src is required.
 *     A CSP nonce approach is scheduled for the same Fase 3 cycle.
 *   - Dashboard charts load Chart.js from the FS node_modules
 *     assets path, which is same-origin.
 *   - Certificate PDFs are served by the plugin, never embedded.
 *   - Images can come from Moodle (https:), data URIs (thumbnails),
 *     or the FS asset path.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F2.11 · §4.14
 */
final class CspHeader
{
    /**
     * Default directives. `'self'` means same-origin as the FS site.
     *
     * @return array<string, string>
     */
    public static function defaultDirectives(): array
    {
        return [
            'default-src' => "'self'",
            // Chart.js falls back to `cdn.jsdelivr.net` when the
            // vendored `Assets/JS/vendor/chart.umd.js` is unavailable
            // (air-gapped installs can remove the CDN — see
            // MoodleDashboard.html.twig header comment).
            'script-src' => "'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            'style-src' => "'self' 'unsafe-inline' https://fonts.googleapis.com",
            'img-src' => "'self' data: https:",
            'font-src' => "'self' data: https://fonts.gstatic.com",
            'connect-src' => "'self'",
            'frame-ancestors' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'object-src' => "'none'",
        ];
    }

    /**
     * Applies the CSP header to the given response. Accepts an
     * optional override map so individual controllers can loosen
     * specific directives (e.g. PDF generator adds its sandbox).
     *
     * Safe to call multiple times; the last call wins.
     *
     * The `$response` argument is untyped so the helper works
     * across FS versions: FS core < 2025.9 passed a
     * `Symfony\Component\HttpFoundation\Response`, FS 2025.9+
     * introduced its own `FacturaScripts\Core\Response` (final,
     * no common ancestor). Both expose a `$headers->set/has` API
     * with the same shape, which is all this helper touches.
     *
     * @param object $response Any object exposing `$headers->set()`
     *                         and `$headers->has()`. In practice
     *                         FS `Response` or Symfony HttpFoundation
     *                         `Response`.
     * @param array<string, string> $overrides
     */
    public static function apply($response, array $overrides = []): void
    {
        if (!is_object($response) || !isset($response->headers)) {
            return;
        }
        $directives = array_merge(self::defaultDirectives(), $overrides);
        $header = '';
        foreach ($directives as $name => $value) {
            if ($value === '') {
                continue;
            }
            if ($header !== '') {
                $header .= '; ';
            }
            $header .= $name . ' ' . $value;
        }

        // Primary header.
        $response->headers->set('Content-Security-Policy', $header);

        // Complementary hardening headers ship from this plugin as
        // defence-in-depth when FS core omits them in older versions.
        if (!$response->headers->has('X-Content-Type-Options')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if (!$response->headers->has('X-Frame-Options')) {
            // Legacy counterpart of frame-ancestors. No cost to
            // ship both for older browsers.
            $response->headers->set('X-Frame-Options', 'DENY');
        }
    }

    /**
     * Controller-facing helper: returns the CSP string without
     * applying it. Used by controllers that build their own
     * response object (e.g. PDF downloads) and want to merge the
     * directive with other headers manually.
     */
    public static function buildHeader(array $overrides = []): string
    {
        $directives = array_merge(self::defaultDirectives(), $overrides);
        $pieces = [];
        foreach ($directives as $name => $value) {
            if ($value !== '') {
                $pieces[] = $name . ' ' . $value;
            }
        }
        return implode('; ', $pieces);
    }

    private function __construct()
    {
    }
}
