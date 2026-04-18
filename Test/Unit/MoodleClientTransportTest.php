<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use PHPUnit\Framework\TestCase;

/**
 * Tiny, dependency-free unit tests for the transport guards added by
 * SEC-03 (2026-04-17): MoodleClient must reject plain-HTTP endpoints
 * outside development, and must recognise `https://` regardless of
 * case or path.
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::endpointUsesTls
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::isDevelopmentEnvironment
 */
final class MoodleClientTransportTest extends TestCase
{
    /**
     * @dataProvider tlsDetectionCases
     */
    public function testEndpointUsesTls(string $endpoint, bool $expected): void
    {
        self::assertSame($expected, MoodleClient::endpointUsesTls($endpoint));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function tlsDetectionCases(): array
    {
        return [
            'plain-http'           => ['http://moodle.example.org/webservice/rest/server.php', false],
            'https-lower'          => ['https://moodle.example.org/webservice/rest/server.php', true],
            'https-upper-scheme'   => ['HTTPS://moodle.example.org/', true],
            'mixed-case-scheme'    => ['HtTpS://moodle.example.org/', true],
            'localhost-plain'      => ['http://localhost:8080', false],
            'missing-scheme'       => ['moodle.example.org/api', false],
            'junk'                 => ['not-a-url', false],
            'empty'                => ['', false],
            'ftp'                  => ['ftp://moodle.example.org/', false],
            'javascript-like'      => ['javascript:alert(1)', false],
        ];
    }

    public function testIsDevelopmentEnvironmentRespectsDebugFlag(): void
    {
        if (defined('FS_DEBUG')) {
            // The constant is already pinned by a prior test run / FS
            // bootstrap. We can only assert that the helper surfaces
            // whatever FS_DEBUG carries — which is the contract the
            // SEC-03 guard relies on.
            self::assertSame((bool) constant('FS_DEBUG'), MoodleClient::isDevelopmentEnvironment());
            return;
        }
        // Undefined constant → must never flip the helper to true.
        self::assertFalse(MoodleClient::isDevelopmentEnvironment());
    }
}
