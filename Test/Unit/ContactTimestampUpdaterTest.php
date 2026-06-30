<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Contact\ContactTimestampUpdater;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Contact\ContactTimestampUpdater
 */
final class ContactTimestampUpdaterTest extends TestCase
{
    public function testRejectsZeroOrNegativeId(): void
    {
        self::assertFalse(ContactTimestampUpdater::touch(0));
        self::assertFalse(ContactTimestampUpdater::touch(-1));
    }

    public function testColumnConstantMatchesSchema(): void
    {
        self::assertSame('mm_last_modified', ContactTimestampUpdater::COLUMN);
    }

    public function testSqlDoesNotInterpolateRawId(): void
    {
        // Contract: the id must be cast to int before interpolation
        // — guard against future refactors reintroducing string
        // concatenation of user input into the raw SQL.
        $source = (string) file_get_contents(
            __DIR__ . '/../../Lib/Contact/ContactTimestampUpdater.php'
        );
        self::assertStringContainsString(
            'WHERE idcontacto = \' . $idcontacto',
            $source,
            'BE-07: $idcontacto must be concatenated as an int, not as a quoted string.'
        );
        // Explicit int parameter type on the method signature.
        self::assertMatchesRegularExpression(
            '/function\s+touch\(int\s+\$idcontacto/',
            $source,
            'BE-07: touch() must declare $idcontacto as int in its signature.'
        );
    }
}
