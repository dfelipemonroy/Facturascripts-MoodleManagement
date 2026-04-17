<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\HtmlSanitizer
 */
final class HtmlSanitizerTest extends TestCase
{
    public function testEscapeEntities(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            HtmlSanitizer::escape('<script>alert(1)</script>')
        );
    }

    public function testEscapeNullReturnsEmpty(): void
    {
        self::assertSame('', HtmlSanitizer::escape(null));
    }

    public function testEscapeWithLineBreaks(): void
    {
        $out = HtmlSanitizer::escapeWithLineBreaks("line1\nline2");
        self::assertStringContainsString('<br>', $out);
        self::assertStringContainsString('line1', $out);
        // ensure no raw unescaped tag escaped from input
        self::assertStringNotContainsString('<script>', $out);
    }

    public function testAllowlistStripsScript(): void
    {
        $input = '<p>hi</p><script>evil()</script>';
        $out = HtmlSanitizer::allowlist($input);
        self::assertStringContainsString('<p>hi</p>', $out);
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringNotContainsString('evil()', $out);
    }

    public function testAllowlistDropsJavascriptHref(): void
    {
        $input = '<a href="javascript:alert(1)">click</a>';
        $out = HtmlSanitizer::allowlist($input);
        self::assertStringNotContainsString('javascript:', $out);
        // The anchor tag survives (without the dangerous href) or the
        // content is reduced to its text — either outcome is safe.
    }

    public function testAllowlistKeepsSafeLinks(): void
    {
        $input = '<a href="https://example.com">go</a>';
        $out = HtmlSanitizer::allowlist($input);
        self::assertStringContainsString('https://example.com', $out);
        self::assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function testAllowlistEmptyInput(): void
    {
        self::assertSame('', HtmlSanitizer::allowlist(null));
        self::assertSame('', HtmlSanitizer::allowlist(''));
    }
}
