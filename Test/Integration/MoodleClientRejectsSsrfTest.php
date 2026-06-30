<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.6 (without a full cURL mock)
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::callApi
 *
 * Verifies the SSRF guard short-circuits before any network call
 * happens, so misconfigured instance URLs never reach the
 * cURL transport.
 *
 * F14 note — MoodleInstance is built without its constructor so
 * FS DbUpdater is not triggered. The SSRF guard runs purely off
 * the public properties we set, so skipping the constructor is
 * safe.
 */
final class MoodleClientRejectsSsrfTest extends TestCase
{
    private static function instance(array $props): MoodleInstance
    {
        /** @var MoodleInstance $i */
        $i = (new ReflectionClass(MoodleInstance::class))->newInstanceWithoutConstructor();
        foreach ($props as $k => $v) {
            $i->{$k} = $v;
        }
        return $i;
    }

    public function testCallApiRejectsLocalhostUrl(): void
    {
        $instance = self::instance(['id' => 1, 'url' => 'http://127.0.0.1', 'token' => 'dummy']);
        $result = MoodleClient::callApi($instance, 'core_webservice_get_site_info', []);
        self::assertIsArray($result);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }

    public function testCallApiRejectsAwsMetadata(): void
    {
        $instance = self::instance(['id' => 2, 'url' => 'http://169.254.169.254/latest/meta-data', 'token' => 'dummy']);
        $result = MoodleClient::callApi($instance, 'any', []);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }

    public function testCallApiRejectsPrivateIpv6(): void
    {
        $instance = self::instance(['id' => 3, 'url' => 'http://[::1]', 'token' => 'dummy']);
        $result = MoodleClient::callApi($instance, 'any', []);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }
}
