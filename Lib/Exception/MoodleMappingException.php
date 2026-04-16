<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown when a FS entity cannot be mapped to / from a Moodle entity:
 *   - Contacto without a MoodleUserMap.
 *   - Product missing a MoodleCourseMap row.
 *   - Contact email collides with another Moodle user.
 *
 * NOT eligible for retry — the caller must surface the issue to an
 * operator for manual resolution.
 *
 * @since 2.0
 */
class MoodleMappingException extends MoodleException
{
}
