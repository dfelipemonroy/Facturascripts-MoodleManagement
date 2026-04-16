<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown when input data fails local validation before hitting the
 * Moodle API:
 *   - Invalid URL scheme/format (Fase 7 F7.10).
 *   - Path traversal attempt in certificate logo (Fase 7 F7.8).
 *   - CSV value triggering formula-injection pattern (Fase 2 F2.7).
 *   - Enum mismatch against Lib/Enum/* ::isValid().
 *
 * @since 2.0
 */
class MoodleValidationException extends MoodleException
{
}
