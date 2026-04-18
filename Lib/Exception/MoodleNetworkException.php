<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

/**
 * Thrown for transport-level failures: DNS resolution, connect
 * timeout, SSL handshake, 5xx responses, I/O truncation.
 *
 * These are considered transient and eligible for the retry policy
 * in Fase 6 F6.7.
 *
 * @since 2.0
 */
class MoodleNetworkException extends MoodleException
{
}
