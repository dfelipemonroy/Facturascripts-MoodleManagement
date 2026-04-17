<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\CsvEscaper;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\CsvEscaper
 */
final class CsvEscaperTest extends TestCase
{
    /**
     * @dataProvider dangerousProvider
     */
    public function testDangerousPrefixesAreQuoted(string $input, string $expected): void
    {
        self::assertSame($expected, CsvEscaper::escape($input));
    }

    public static function dangerousProvider(): array
    {
        return [
            ['=SUM(A1:A2)',                      "'=SUM(A1:A2)"],
            ['+cmd|"/c calc"!A0',                "'+cmd|\"/c calc\"!A0"],
            ['-1+1',                             "'-1+1"],
            ['@WEBSERVICE',                      "'@WEBSERVICE"],
            ["\tleadingtab",                     "'\tleadingtab"],
            ["\rleadingcr",                      "'\rleadingcr"],
        ];
    }

    public function testSafeValuesPassThrough(): void
    {
        self::assertSame('Juan Pérez', CsvEscaper::escape('Juan Pérez'));
        self::assertSame('25.99', CsvEscaper::escape('25.99'));
        self::assertSame('42', CsvEscaper::escape(42));
    }

    public function testNullsAndBooleans(): void
    {
        self::assertSame('', CsvEscaper::escape(null));
        self::assertSame('', CsvEscaper::escape(false));
        self::assertSame('1', CsvEscaper::escape(true));
    }

    public function testEscapeRow(): void
    {
        $row = [
            'name'  => '=cmd',
            'email' => 'jp@example.com',
            'qty'   => 5,
        ];
        $expected = [
            'name'  => "'=cmd",
            'email' => 'jp@example.com',
            'qty'   => '5',
        ];
        self::assertSame($expected, CsvEscaper::escapeRow($row));
    }
}
