<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\View\JsonForScript;
use PHPUnit\Framework\TestCase;

/**
 * Covers the FE-01 / FE-02 fix: script-context JSON interpolation must
 * not carry raw `<`, `'`, `"`, `&` characters that could close the
 * surrounding `<script>` block or break out of an HTML attribute.
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\View\JsonForScript
 */
final class JsonForScriptTest extends TestCase
{
    public function testEscapesScriptTagClose(): void
    {
        $out = JsonForScript::encode(['label' => 'abc</script>def']);
        self::assertStringNotContainsString('</script>', $out);
        self::assertStringContainsString('\u003C', $out);
    }

    public function testEscapesSingleQuotes(): void
    {
        $out = JsonForScript::encode(["O'Brien"]);
        self::assertStringNotContainsString("'", $out);
        self::assertStringContainsString('\u0027', $out);
    }

    public function testEscapesAmpersand(): void
    {
        $out = JsonForScript::encode(['Tom & Jerry']);
        self::assertStringNotContainsString('&', $out);
        self::assertStringContainsString('\u0026', $out);
    }

    public function testEscapesDoubleQuotesWhenEmbedded(): void
    {
        $out = JsonForScript::encode(['say "hi"']);
        // Double quotes appear as JSON delimiters, but internal ones
        // must be hex-escaped so attribute-quote contexts stay safe.
        self::assertStringContainsString('\u0022', $out);
    }

    public function testUnicodeSurvives(): void
    {
        $out = JsonForScript::encode(['Köln', 'Zürich', '漢字']);
        self::assertStringContainsString('Köln', $out);
        self::assertStringContainsString('漢字', $out);
    }

    public function testNestedArraysAndObjects(): void
    {
        $out = JsonForScript::encode([
            'label' => 'A',
            'items' => [['id' => 1, 'name' => '<b>X</b>']],
        ]);
        self::assertJson($out);
        self::assertStringNotContainsString('<b>', $out);
    }

    public function testThrowsOnMalformedInput(): void
    {
        $this->expectException(\JsonException::class);
        // fopen resource is not JSON-encodable.
        $stream = fopen('php://memory', 'r');
        try {
            JsonForScript::encode($stream);
        } finally {
            fclose($stream);
        }
    }

    public function testProducesValidJsonAlwaysScalar(): void
    {
        self::assertSame('1', JsonForScript::encode(1));
        self::assertSame('true', JsonForScript::encode(true));
        self::assertSame('null', JsonForScript::encode(null));
        self::assertSame('[]', JsonForScript::encode([]));
    }
}
