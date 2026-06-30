<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown when the Moodle Web Service returns a semantic error,
 * e.g. the JSON response includes `{"exception": "dml_missing_record_exception", ...}`.
 *
 * @since 2.0
 */
class MoodleApiException extends MoodleException
{
}
