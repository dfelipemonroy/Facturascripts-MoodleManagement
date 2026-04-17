<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Logger\PiiMasker;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Logger\PiiMasker
 */
final class PiiMaskerTest extends TestCase
{
    public function testEmailShape(): void
    {
        self::assertSame('j***@e***.com', PiiMasker::email('juan.perez@example.com'));
    }

    public function testEmailMultipleDots(): void
    {
        self::assertSame('j***@s***.co.uk', PiiMasker::email('juan@sub.company.co.uk'));
    }

    public function testEmailEmpty(): void
    {
        self::assertSame('', PiiMasker::email(null));
        self::assertSame('', PiiMasker::email(''));
        self::assertSame('', PiiMasker::email('not-an-email'));
    }

    public function testName(): void
    {
        self::assertSame('J*** P***', PiiMasker::name('Juan Pérez'));
        self::assertSame('', PiiMasker::name(''));
    }

    public function testPhone(): void
    {
        $out = PiiMasker::phone('+34 600 123 456');
        self::assertStringEndsWith('456', $out);
        self::assertStringStartsWith('+', $out);
        self::assertStringContainsString('***', $out);
    }

    public function testDniKeepsFirst4AndLast(): void
    {
        self::assertSame('1234****Z', PiiMasker::dni('12345678Z'));
    }
}
