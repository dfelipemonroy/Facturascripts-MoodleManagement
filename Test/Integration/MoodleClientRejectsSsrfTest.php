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

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.6 (without a full cURL mock)
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::callApi
 *
 * Verifies the SSRF guard short-circuits before any network call
 * happens, so misconfigured instance URLs never reach the
 * cURL transport.
 */
final class MoodleClientRejectsSsrfTest extends TestCase
{
    public function testCallApiRejectsLocalhostUrl(): void
    {
        $instance = new MoodleInstance();
        $instance->id = 1;
        $instance->url = 'http://127.0.0.1';
        $instance->token = 'dummy';

        $result = MoodleClient::callApi($instance, 'core_webservice_get_site_info', []);
        self::assertIsArray($result);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }

    public function testCallApiRejectsAwsMetadata(): void
    {
        $instance = new MoodleInstance();
        $instance->id = 2;
        $instance->url = 'http://169.254.169.254/latest/meta-data';
        $instance->token = 'dummy';

        $result = MoodleClient::callApi($instance, 'any', []);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }

    public function testCallApiRejectsPrivateIpv6(): void
    {
        $instance = new MoodleInstance();
        $instance->id = 3;
        $instance->url = 'http://[::1]';
        $instance->token = 'dummy';

        $result = MoodleClient::callApi($instance, 'any', []);
        self::assertSame('ssrf_rejected', $result['exception'] ?? null);
    }
}
