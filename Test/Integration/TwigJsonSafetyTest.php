<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression tests for audit FE-01 and FE-02 (2026-04-17).
 * The MoodleDashboard controller pre-serialises every chart payload
 * through `JsonForScript`, and every remaining template interpolation
 * that lands inside a `<script>` block must use the `json_for_script`
 * Twig function registered by `Init::init`.
 *
 * These assertions guard against silent regression in code review,
 * which mirrors the CI grep linter but keeps the contract visible
 * inside the PHPUnit suite too.
 */
final class TwigJsonSafetyTest extends TestCase
{
    private const PLUGIN_ROOT = __DIR__ . '/../..';

    public function testNoRawJsonEncodeInTemplates(): void
    {
        $offenders = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_ROOT . '/View')
        );
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/json_encode\s*\|\s*raw/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame(
            [],
            $offenders,
            'FE-02 regression: Twig templates must not use `json_encode | raw`. '
            . 'Use `json_for_script(value)` instead.'
        );
    }

    public function testInitRegistersJsonForScriptFunction(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_ROOT . '/Init.php');
        self::assertStringContainsString(
            'json_for_script',
            $source,
            'FE-02 regression: Init must register the `json_for_script` Twig function.'
        );
        self::assertStringContainsString(
            'JsonForScript::encode',
            $source,
            'FE-02 regression: the function must delegate to JsonForScript::encode.'
        );
    }

    public function testDashboardControllerPreEncodesChartPayloads(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_ROOT . '/Controller/MoodleDashboard.php');
        self::assertStringContainsString(
            'JsonForScript::encode',
            $source,
            'FE-01 regression: MoodleDashboard controller must pre-encode chart data.'
        );
        foreach (['monthLabelsJson', 'monthDataJson', 'topCourseLabelsJson', 'topCourseDataJson', 'methodLabelsJson', 'methodDataJson'] as $key) {
            self::assertStringContainsString(
                $key,
                $source,
                sprintf('FE-01 regression: MoodleDashboard must populate `%s`.', $key)
            );
        }
    }
}
