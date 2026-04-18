<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown when a model hands us a schema shape that the helper refuses
 * to operate on (composite PK on a soft-delete table, missing required
 * column on a migration precondition, …).
 *
 * @since 2.0 — DB-02 (2026-04-17)
 */
class UnsupportedSchemaException extends MoodleException
{
}
