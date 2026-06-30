<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Contract;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Transport-level contract for Moodle WS calls.
 *
 * Consumers should depend on this interface instead of the
 * concrete MoodleClient so Fase 9 tests can inject a fake / stub
 * implementation without touching HTTP.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.10, F8.11 · §1.19, §2.24
 */
interface HttpClientInterface
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return array Decoded response or error wrapper
     *               (['exception' => string, 'message' => string]).
     */
    public function call(MoodleInstance $instance, string $function, array $params = [], array $options = []): array;

    /**
     * @return string Local filename on success, '' on failure.
     */
    public function download(MoodleInstance $instance, string $fileUrl): string;
}
