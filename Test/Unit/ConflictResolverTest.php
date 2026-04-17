<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\ConflictResolver;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.3
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::resolveConflict
 */
final class ConflictResolverTest extends TestCase
{
    public function testFsWinsAlwaysReturnsFs(): void
    {
        self::assertSame(
            'fs',
            MoodleClient::resolveConflict('fs_wins', '2020-01-01 00:00:00', '2099-01-01')
        );
    }

    public function testMoodleWinsAlwaysReturnsMoodle(): void
    {
        self::assertSame(
            'moodle',
            MoodleClient::resolveConflict('moodle_wins', '2099-01-01 00:00:00', '1999-01-01')
        );
    }

    public function testUnknownPriorityReturnsConflict(): void
    {
        self::assertSame(
            'conflict',
            MoodleClient::resolveConflict('invalid', '2020-01-01', '2021-01-01')
        );
    }

    public function testNewestWinsFsLater(): void
    {
        $fs = '2024-06-01 12:00:00';        // later
        $moodle = strtotime('2024-06-01 10:00:00 UTC');
        self::assertSame('fs', MoodleClient::resolveConflict('newest_wins', $fs, (string) $moodle));
    }

    public function testNewestWinsMoodleLater(): void
    {
        $fs = '2024-06-01 10:00:00';
        $moodle = strtotime('2024-06-01 12:00:00 UTC');
        self::assertSame('moodle', MoodleClient::resolveConflict('newest_wins', $fs, (string) $moodle));
    }

    public function testNewestWinsBothEmptyReturnsFs(): void
    {
        self::assertSame('fs', MoodleClient::resolveConflict('newest_wins', null, null));
    }

    public function testFluentWrapperDelegates(): void
    {
        self::assertSame(
            'fs',
            ConflictResolver::decide(ConflictResolver::PRIORITY_FS_WINS, '2020-01-01', '2099-01-01')
        );
    }
}
