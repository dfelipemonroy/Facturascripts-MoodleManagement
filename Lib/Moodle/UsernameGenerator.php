<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Namespaced facade over {@see MoodleClient::generateUsername()} and
 * {@see MoodleClient::generateUniqueUsername()}. Lets unit tests and
 * future callers depend on a small surface (two methods, one
 * responsibility) instead of the 1600-line god class.
 *
 * Back-compat: methods delegate to MoodleClient for now so existing
 * callers keep working. The long-term plan is to invert the
 * dependency (MoodleClient delegates here) once every caller has
 * migrated.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1 split · §1.4
 */
final class UsernameGenerator
{
    /**
     * Normalised candidate (no uniqueness check). See
     * {@see MoodleClient::generateUsername()} for the full rules.
     */
    public static function candidate(Contacto $contact): string
    {
        return MoodleClient::generateUsername($contact);
    }

    /**
     * Unique candidate verified against the target instance via
     * core_user_get_users_by_field. Numeric suffix up to 99, then
     * 8-char random hex fallback.
     */
    public static function unique(Contacto $contact, MoodleInstance $instance): string
    {
        return MoodleClient::generateUniqueUsername($contact, $instance);
    }

    private function __construct()
    {
    }
}
