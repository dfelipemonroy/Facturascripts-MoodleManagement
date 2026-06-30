<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * File operations (download + overview). All hardening applied in
 * Fase 7 F7.2/F7.6/F7.11 lives in MoodleClient; this namespace just
 * exposes the stable contract.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1
 */
final class FileApi
{
    public static function download(MoodleInstance $instance, string $fileUrl): string
    {
        return MoodleClient::downloadFile($instance, $fileUrl);
    }

    public static function getOverviewFiles(MoodleInstance $instance, int $courseId): array
    {
        return MoodleClient::getOverviewFiles($instance, $courseId);
    }

    private function __construct()
    {
    }
}
