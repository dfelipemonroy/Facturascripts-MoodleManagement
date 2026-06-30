<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Thin namespaced facade over {@see MoodleClient::callApi()} and
 * {@see MoodleClient::downloadFile()} — the two transport-level
 * entry points.
 *
 * Kept narrow so Fase 9 can unit-test a mock that implements a
 * matching interface. The concrete class still delegates to
 * MoodleClient so existing callers keep working (F8.1 split).
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1 · §1.4
 */
final class HttpClient
{
    /**
     * Invoke a Moodle WS function. Options flow through to
     * {@see MoodleClient::callApi()} (timeout_profile, max_bytes, …).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public static function call(
        MoodleInstance $instance,
        string $function,
        array $params = [],
        array $options = []
    ): array {
        return MoodleClient::callApi($instance, $function, $params, $options);
    }

    /**
     * Download a Moodle-hosted file to the local MyFiles folder.
     * Returns the local filename on success, or empty string on failure.
     */
    public static function download(MoodleInstance $instance, string $fileUrl): string
    {
        return MoodleClient::downloadFile($instance, $fileUrl);
    }

    private function __construct()
    {
    }
}
