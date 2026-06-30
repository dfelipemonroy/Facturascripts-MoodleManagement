<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.7
 *
 * Structural regression test for the F6.1 cascade cut.
 * Loads Init.php as text and asserts:
 *   - BadgeSyncWorker is bound to Model.MoodleUserMap.Insert (not .Save).
 *   - OnboardingWorker also bound to Insert.
 *   - PreEnrolmentWorker subscribes to line-delete events (F6.10).
 *
 * Does NOT boot the FS WorkQueue — that would need a live DB.
 * A pure-string assertion is enough to guarantee the wiring
 * declared in source cannot regress silently.
 */
final class WorkerCascadeGuardTest extends TestCase
{
    private function initSource(): string
    {
        $path = __DIR__ . '/../../Init.php';
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testBadgeSyncWorkerBoundToInsertNotSave(): void
    {
        $src = $this->initSource();
        self::assertStringContainsString(
            "WorkQueue::addWorker('BadgeSyncWorker', 'Model.MoodleUserMap.Insert')",
            $src,
            'F6.1 regression: BadgeSyncWorker must be bound to Insert.'
        );
        self::assertStringNotContainsString(
            "WorkQueue::addWorker('BadgeSyncWorker', 'Model.MoodleUserMap.Save')",
            $src,
            'F6.1 regression: BadgeSyncWorker must NOT be bound to Save (cascade risk).'
        );
    }

    public function testOnboardingWorkerBoundToInsert(): void
    {
        self::assertStringContainsString(
            "WorkQueue::addWorker('OnboardingWorker', 'Model.MoodleUserMap.Insert')",
            $this->initSource()
        );
    }

    public function testPreEnrolmentWorkerSubscribesToLineDelete(): void
    {
        $src = $this->initSource();
        self::assertStringContainsString("'Model.LineaPresupuestoCliente.Delete'", $src);
        self::assertStringContainsString("'Model.LineaPedidoCliente.Delete'", $src);
    }
}
