<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Http;

/**
 * Cross-version helpers for the FS Request object.
 *
 * FS < 2025.9 used Symfony's `HttpFoundation\Request`, which exposes
 * `getClientIp()` among other helpers. FS 2025.9 introduced its own
 * `FacturaScripts\Core\Request` which replaces those helpers with a
 * shorter naming (`ip()`, `browser()`, …). The plugin must keep
 * working on both.
 *
 * This class is intentionally framework-version-agnostic: each
 * helper reflects-checks what is available at runtime rather than
 * depending on a specific type hint.
 *
 * @since 2.0 — 2026-04-19 (FS 2025.9 compat)
 */
final class RequestCompat
{
    /**
     * Return the client IP as seen by the request. Never null —
     * returns `''` when no method is available.
     *
     * @param object|null $request Typically the controller's
     *                             `$this->request`; accepts any
     *                             FS- or Symfony-style Request.
     */
    public static function clientIp($request): string
    {
        if (!is_object($request)) {
            return '';
        }
        if (method_exists($request, 'ip')) {
            // FS\Core\Request (2025.9+)
            return (string) $request->ip();
        }
        if (method_exists($request, 'getClientIp')) {
            // Symfony HttpFoundation Request (pre-2025.9).
            return (string) ($request->getClientIp() ?? '');
        }
        return '';
    }

    /**
     * Return a header value, empty string on miss. Works with both
     * FS `Core\Internal\Headers` and Symfony `HeaderBag`: both expose
     * `headers->get($name, $default)`.
     */
    public static function header($request, string $name, string $default = ''): string
    {
        if (!is_object($request) || !isset($request->headers)) {
            return $default;
        }
        try {
            $value = $request->headers->get($name, $default);
        } catch (\Throwable $e) {
            return $default;
        }
        return is_string($value) ? $value : $default;
    }

    private function __construct()
    {
    }
}
