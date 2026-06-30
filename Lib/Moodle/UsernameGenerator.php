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
 * F10.5 adds a second strategy: `random_alias`, which returns an
 * opaque token instead of leaking the contact's name as username.
 * Per-instance opt-in via MoodleInstance::$username_strategy.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1 split · §1.4
 */
final class UsernameGenerator
{
    public const STRATEGY_NAME_BASED = 'name_based';
    public const STRATEGY_RANDOM_ALIAS = 'random_alias';

    /** Prefix for the opaque alias strategy. Helps operators tell the
     *  plugin's accounts apart from Moodle-native ones when scrolling
     *  user admin screens. */
    private const ALIAS_PREFIX = 'mu_';

    /** Random suffix length in hex chars (1 char = 4 bits of entropy).
     *  12 hex chars ≈ 48 bits — collision-unlikely across 100k users. */
    private const ALIAS_BYTES = 6;

    /**
     * Normalised candidate (no uniqueness check). See
     * {@see MoodleClient::generateUsername()} for the full rules.
     */
    public static function candidate(Contacto $contact): string
    {
        return MoodleClient::generateUsername($contact);
    }

    /**
     * Maximum number of random_alias regenerate attempts before we
     * accept whatever comes out. 48 bits of entropy make a collision
     * on any single try extremely unlikely; re-trying a handful of
     * times closes the residual gap without blocking enrolment if
     * the WS call keeps returning a collision for some reason.
     *
     * @since 2.0 — INT-06 (2026-04-17)
     */
    public const RANDOM_ALIAS_MAX_PROBES = 5;

    /**
     * Unique candidate verified against the target instance via
     * core_user_get_users_by_field. Numeric suffix up to 99, then
     * 8-char random hex fallback.
     *
     * INT-06 (2026-04-17) — when the strategy is `random_alias` we
     * now probe Moodle with `getUsersByField('username', [candidate])`
     * up to `RANDOM_ALIAS_MAX_PROBES` times and regenerate on
     * collision. Previously the first generated alias was returned
     * blindly; a 48-bit collision is unlikely but not impossible at
     * 100k+ user scale, and the only recovery was a failed enrolment.
     */
    public static function unique(Contacto $contact, MoodleInstance $instance): string
    {
        if (self::strategyFor($instance) === self::STRATEGY_RANDOM_ALIAS) {
            for ($attempt = 0; $attempt < self::RANDOM_ALIAS_MAX_PROBES; $attempt++) {
                $candidate = self::randomAlias();
                if (!self::aliasExistsOnInstance($candidate, $instance)) {
                    return $candidate;
                }
            }
            // All probes returned collisions (or the probe itself is
            // unavailable). Emit the last candidate — the subsequent
            // enrol_user call will surface any genuine collision via
            // the standard error path.
            return $candidate;
        }
        return MoodleClient::generateUniqueUsername($contact, $instance);
    }

    /**
     * INT-06 helper — returns true when the candidate username already
     * maps to a user on the target instance. False when the WS probe
     * fails (fail-open so a transient Moodle outage does not stall
     * onboarding; the caller will catch the real conflict on the
     * enrol call).
     *
     * @since 2.0 — INT-06 (2026-04-17)
     */
    private static function aliasExistsOnInstance(string $candidate, MoodleInstance $instance): bool
    {
        if ($candidate === '') {
            return false;
        }
        try {
            $result = MoodleClient::getUsersByField($instance, 'username', [$candidate]);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($result) || isset($result['exception'])) {
            return false;
        }
        return !empty($result);
    }

    /**
     * Returns a fresh opaque alias like "mu_8d42c1f93b7a".
     *
     * Entropy comes from random_bytes(). On systems without a CSPRNG
     * (unlikely on PHP 8.0+), we fall back to a hashed microtime —
     * less secure but good enough to keep username generation moving.
     * Operators should verify /dev/urandom is available via F10.7.
     */
    public static function randomAlias(): string
    {
        try {
            $hex = bin2hex(random_bytes(self::ALIAS_BYTES));
        } catch (\Throwable $e) {
            $hex = substr(sha1((string) microtime(true) . (string) getmypid()), 0, self::ALIAS_BYTES * 2);
        }
        return self::ALIAS_PREFIX . $hex;
    }

    /**
     * Resolves the strategy for the given instance. Defaults to
     * name-based when the column is missing (pre-F10.5 installs) or
     * holds an unknown value.
     */
    public static function strategyFor(MoodleInstance $instance): string
    {
        if (property_exists($instance, 'username_strategy')) {
            $value = (string) $instance->username_strategy;
            if ($value === self::STRATEGY_RANDOM_ALIAS) {
                return self::STRATEGY_RANDOM_ALIAS;
            }
        }
        return self::STRATEGY_NAME_BASED;
    }

    private function __construct()
    {
    }
}
