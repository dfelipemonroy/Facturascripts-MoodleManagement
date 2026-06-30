<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\CertificateStatus;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\EnrolmentStatus;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\InstanceStatus;
use FacturaScripts\Plugins\MoodleManagement\Lib\Enum\UserMapStatus;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Enum\EnrolmentStatus
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Enum\InstanceStatus
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Enum\UserMapStatus
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Enum\CertificateStatus
 */
final class EnumsTest extends TestCase
{
    public function testEnrolmentStatusAll(): void
    {
        $all = EnrolmentStatus::all();
        self::assertContains(EnrolmentStatus::PENDING, $all);
        self::assertContains(EnrolmentStatus::ENROLLED, $all);
        self::assertContains(EnrolmentStatus::SUSPENDED, $all);
        self::assertTrue(EnrolmentStatus::isValid('enrolled'));
        self::assertFalse(EnrolmentStatus::isValid('bogus'));
    }

    public function testInstanceStatus(): void
    {
        self::assertTrue(InstanceStatus::isValid(InstanceStatus::ACTIVE));
        self::assertFalse(InstanceStatus::isValid('maintenance'));
    }

    public function testUserMapStatus(): void
    {
        self::assertTrue(UserMapStatus::isValid(UserMapStatus::MAPPED));
        self::assertFalse(UserMapStatus::isValid(''));
    }

    public function testCertificateStatusBootstrapContext(): void
    {
        self::assertSame('success', CertificateStatus::bootstrapContext(CertificateStatus::ACTIVE));
        self::assertSame('warning', CertificateStatus::bootstrapContext(CertificateStatus::PENDING));
        self::assertSame('danger', CertificateStatus::bootstrapContext(CertificateStatus::EXPIRED));
        self::assertSame('danger', CertificateStatus::bootstrapContext(CertificateStatus::REVOKED));
        self::assertSame('secondary', CertificateStatus::bootstrapContext('unknown-value'));
    }
}
