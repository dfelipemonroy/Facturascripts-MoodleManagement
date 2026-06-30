<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle;

use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\BadgeApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\CohortApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\CourseApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\EnrolmentApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\FileApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api\UserApi;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Instance-bound fluent wrapper around the Lib/Moodle/Api/* helpers.
 * Replaces the pattern
 *
 *     MoodleClient::getUsers($instance, ...)
 *     MoodleClient::enrolUsers($instance, ...)
 *     MoodleClient::getCourseById($instance, 42)
 *
 * with
 *
 *     $mm = MoodleClient::forInstance($instance);
 *     $mm->users()->get(...)
 *     $mm->enrolments()->enrol(...)
 *     $mm->courses()->getById(42)
 *
 * so the instance parameter is not repeated and test setups can
 * construct one `BoundClient` per test fixture.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.7 · §1.15
 */
final class BoundClient
{
    /** @var MoodleInstance */
    private $instance;

    public function __construct(MoodleInstance $instance)
    {
        $this->instance = $instance;
    }

    public function instance(): MoodleInstance
    {
        return $this->instance;
    }

    // ── Fluent accessors ─────────────────────────────────────────

    public function http(): BoundHttp
    {
        return new BoundHttp($this->instance);
    }

    public function users(): BoundUserApi
    {
        return new BoundUserApi($this->instance);
    }

    public function courses(): BoundCourseApi
    {
        return new BoundCourseApi($this->instance);
    }

    public function enrolments(): BoundEnrolmentApi
    {
        return new BoundEnrolmentApi($this->instance);
    }

    public function cohorts(): BoundCohortApi
    {
        return new BoundCohortApi($this->instance);
    }

    public function badges(): BoundBadgeApi
    {
        return new BoundBadgeApi($this->instance);
    }

    public function files(): BoundFileApi
    {
        return new BoundFileApi($this->instance);
    }
}

/** @internal */
final class BoundHttp
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function call(string $fn, array $params = [], array $options = []): array
    {
        return MoodleClient::callApi($this->i, $fn, $params, $options);
    }
    public function download(string $url): string
    {
        return MoodleClient::downloadFile($this->i, $url);
    }
}

/** @internal */
final class BoundUserApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function get(array $criteria = []): array
    {
        return UserApi::get($this->i, $criteria);
    }
    public function getByField(string $field, array $values): array
    {
        return UserApi::getByField($this->i, $field, $values);
    }
    public function create(array $users): array
    {
        return UserApi::create($this->i, $users);
    }
    public function update(int $userId, array $data): array
    {
        return UserApi::update($this->i, $userId, $data);
    }
    public function suspend(int $userId, bool $suspended = true): array
    {
        return UserApi::suspend($this->i, $userId, $suspended);
    }
}

/** @internal */
final class BoundCourseApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function getAll(array $courseIds = []): array
    {
        return CourseApi::getAll($this->i, $courseIds);
    }
    public function getById(int $courseId): ?array
    {
        return CourseApi::getById($this->i, $courseId);
    }
    public function getByField(string $field = '', string $value = ''): array
    {
        return CourseApi::getByField($this->i, $field, $value);
    }
    public function getContents(int $courseId): array
    {
        return CourseApi::getContents($this->i, $courseId);
    }
}

/** @internal */
final class BoundEnrolmentApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function enrol(array $enrolments): array
    {
        return EnrolmentApi::enrol($this->i, $enrolments);
    }
    public function unenrol(array $enrolments): array
    {
        return EnrolmentApi::unenrol($this->i, $enrolments);
    }
    public function getEnrolled(int $courseId, bool $includeHidden = false): array
    {
        return EnrolmentApi::getEnrolled($this->i, $courseId, $includeHidden);
    }
    public function getMethods(int $courseId): array
    {
        return EnrolmentApi::getMethods($this->i, $courseId);
    }
}

/** @internal */
final class BoundCohortApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function getAll(): array
    {
        return CohortApi::getAll($this->i);
    }
    public function addMembers(int $cohortId, array $ids): array
    {
        return CohortApi::addMembers($this->i, $cohortId, $ids);
    }
    public function removeMembers(int $cohortId, array $ids): array
    {
        return CohortApi::removeMembers($this->i, $cohortId, $ids);
    }
}

/** @internal */
final class BoundBadgeApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function getUserBadges(int $userId, int $courseId = 0): array
    {
        return BadgeApi::getUserBadges($this->i, $userId, $courseId);
    }
}

/** @internal */
final class BoundFileApi
{
    private $i;
    public function __construct(MoodleInstance $i)
    {
        $this->i = $i;
    }
    public function download(string $url): string
    {
        return FileApi::download($this->i, $url);
    }
    public function overviewFiles(int $courseId): array
    {
        return FileApi::getOverviewFiles($this->i, $courseId);
    }
}
