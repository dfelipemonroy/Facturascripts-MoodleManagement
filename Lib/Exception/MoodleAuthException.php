<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown when the Moodle token is missing, invalid or its user lacks
 * the capability for the invoked WS function.
 *
 * Triggers the circuit-breaker introduced in Fase 6 F6.7.
 *
 * @since 2.0
 */
class MoodleAuthException extends MoodleException
{
}
