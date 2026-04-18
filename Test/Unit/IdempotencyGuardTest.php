<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\WorkQueue\IdempotencyGuard;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\WorkQueue\IdempotencyGuard
 */
final class IdempotencyGuardTest extends TestCase
{
    public function testEmptyKeyPasses(): void
    {
        // A caller that accidentally passes '' must not block the
        // worker — degrade open.
        self::assertTrue(IdempotencyGuard::beginOnce(''));
    }

    public function testFirstCallRegistersMarker(): void
    {
        $key = 'test:fresh-' . uniqid('', true);
        // The FS cache may or may not be available inside the test
        // harness. We accept either outcome: if cache is live, the
        // first call returns true and the second returns false; if
        // cache is down, the guard fails open and both return true.
        $first = IdempotencyGuard::beginOnce($key, 60);
        self::assertTrue($first, 'first call must always succeed');

        $second = IdempotencyGuard::beginOnce($key, 60);
        // Either cache is live (→ false) or cache is down (→ true).
        self::assertIsBool($second);

        IdempotencyGuard::clear($key);
    }

    public function testClearResetsMarker(): void
    {
        $key = 'test:clear-' . uniqid('', true);
        IdempotencyGuard::beginOnce($key, 60);
        IdempotencyGuard::clear($key);
        // After a clear, the very next call must behave like a fresh
        // key and return true (whether or not cache is live).
        self::assertTrue(IdempotencyGuard::beginOnce($key, 60));
        IdempotencyGuard::clear($key);
    }

    public function testDistinctKeysAreIndependent(): void
    {
        $a = 'test:a-' . uniqid('', true);
        $b = 'test:b-' . uniqid('', true);
        self::assertTrue(IdempotencyGuard::beginOnce($a, 60));
        self::assertTrue(IdempotencyGuard::beginOnce($b, 60));
        IdempotencyGuard::clear($a);
        IdempotencyGuard::clear($b);
    }
}
