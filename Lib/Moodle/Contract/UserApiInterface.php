<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Contract;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * User-management contract. Consumers (workers, controllers) that
 * need to stub Moodle user operations depend on this interface.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.10, F8.11 · §1.19
 */
interface UserApiInterface
{
    public function get(MoodleInstance $instance, array $criteria = []): array;

    public function getByField(MoodleInstance $instance, string $field, array $values): array;

    public function create(MoodleInstance $instance, array $users): array;

    public function update(MoodleInstance $instance, int $userId, array $data): array;

    public function suspend(MoodleInstance $instance, int $userId, bool $suspended = true): array;
}
