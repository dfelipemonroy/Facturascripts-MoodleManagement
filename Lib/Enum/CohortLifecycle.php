<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Enum;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F13 · DISCOVERED-05
 *
 * Lifecycle state of a Moodle cohort mirrored in
 * `moodle_cohorts`. The F7.16 review deferred this enum to Fase
 * 10; since it never landed there, Fase 13 closes the gap so
 * operators have a single vocabulary for UI, filters and workers.
 *
 * States
 *   - ACTIVE     — cohort exists on Moodle AND locally; sync OK.
 *   - DETACHED   — exists locally but missing on Moodle (WS 404 on
 *                  last reconciliation). Will be either re-pushed
 *                  or archived depending on operator action.
 *   - ARCHIVED   — operator froze the mapping; sync_active = 0,
 *                  deleted_at = NULL.
 *   - TRASHED    — soft-deleted via F13 DISCOVERED-02 (deleted_at
 *                  IS NOT NULL); waiting for restore or purge.
 *
 * Stored as a computed flag — there is no new DB column. The value
 * is derived on demand from the existing `source`, `sync_active`
 * and `deleted_at` columns; models can expose `lifecycle()` for
 * convenience.
 *
 * Migration: none — this enum is a shape, not a schema change.
 * Keeping it additive means existing cohort rows render correctly
 * without touching the DB.
 */
final class CohortLifecycle
{
    public const ACTIVE   = 'active';
    public const DETACHED = 'detached';
    public const ARCHIVED = 'archived';
    public const TRASHED  = 'trashed';

    /**
     * All values in a single array.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [self::ACTIVE, self::DETACHED, self::ARCHIVED, self::TRASHED];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }

    /**
     * Derive the lifecycle from the three live signals on the model
     * (deleted_at, sync_active, last_error tagged as "missing").
     */
    public static function fromModel(object $cohort): string
    {
        if (property_exists($cohort, 'deleted_at') && !empty($cohort->deleted_at)) {
            return self::TRASHED;
        }
        if (property_exists($cohort, 'sync_active') && $cohort->sync_active === false) {
            return self::ARCHIVED;
        }
        if (property_exists($cohort, 'last_error') && is_string($cohort->last_error)
            && stripos($cohort->last_error, 'not found in moodle') !== false) {
            return self::DETACHED;
        }
        return self::ACTIVE;
    }

    private function __construct()
    {
    }
}
