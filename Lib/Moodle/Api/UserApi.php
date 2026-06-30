<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\Api;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * User-management WS operations namespaced out of MoodleClient
 * (F8.1). Each method here is a thin delegation to the legacy
 * god-class API; callers can start depending on this class today.
 *
 * Future work (v2.1+): the implementation will move here and
 * MoodleClient becomes a deprecation shim.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.1 · §1.4
 */
final class UserApi
{
    public static function get(MoodleInstance $instance, array $criteria = []): array
    {
        return MoodleClient::getUsers($instance, $criteria);
    }

    public static function getByField(MoodleInstance $instance, string $field, array $values): array
    {
        return MoodleClient::getUsersByField($instance, $field, $values);
    }

    public static function create(MoodleInstance $instance, array $users): array
    {
        return MoodleClient::createUsers($instance, $users);
    }

    public static function update(MoodleInstance $instance, int $userId, array $data): array
    {
        return MoodleClient::updateUser($instance, $userId, $data);
    }

    public static function suspend(MoodleInstance $instance, int $userId, bool $suspended = true): array
    {
        return MoodleClient::suspendUser($instance, $userId, $suspended);
    }

    private function __construct()
    {
    }
}
