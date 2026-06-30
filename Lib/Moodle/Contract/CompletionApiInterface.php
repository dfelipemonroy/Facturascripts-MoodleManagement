<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Contract;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F13 · DISCOVERED-03
 *
 * Completion-read contract. Seeds the gap left by Fase 8 F8.10/11/12,
 * which only shipped contracts for HttpClient, UserApi and CourseApi;
 * progressSync (F10.2) needs a stub-able surface too.
 *
 * Implementation: Lib/Moodle/Api/CompletionApi (static facade today,
 * but the interface is already usable through a tiny per-instance
 * adapter when a test or alternate backend is desired).
 *
 * Shape mirrors the static facade — callers that want to inject a
 * mock in tests construct an anonymous class implementing this
 * interface and pass it where the production code currently calls
 * `CompletionApi::getActivities(...)` statically.
 */
interface CompletionApiInterface
{
    /**
     * Activities-level completion. Returns:
     *   ['statuses' => [ {cmid, modname, state, timecompleted, tracking}, … ]]
     * An error is represented by an 'exception' key in the top array.
     */
    public function getActivities(MoodleInstance $instance, int $courseId, int $userId): array;

    /**
     * Course-level completion. Includes a `completionstatus.completed`
     * boolean when Moodle course completion tracking is on.
     */
    public function getCourse(MoodleInstance $instance, int $courseId, int $userId): array;

    /**
     * Grade items for a user — used to populate
     * `moodle_enrolments.final_grade` when the course grade tree
     * exposes a course-total item.
     */
    public function getGradeItems(MoodleInstance $instance, int $userId, int $courseId = 0): array;
}
