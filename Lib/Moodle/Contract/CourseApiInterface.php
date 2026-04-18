<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Contract;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * Course WS contract. @since 2.0 F8.11
 */
interface CourseApiInterface
{
    public function getAll(MoodleInstance $instance, array $courseIds = []): array;

    public function getById(MoodleInstance $instance, int $courseId): ?array;

    public function getByField(MoodleInstance $instance, string $field = '', string $value = ''): array;

    public function getContents(MoodleInstance $instance, int $courseId): array;
}
