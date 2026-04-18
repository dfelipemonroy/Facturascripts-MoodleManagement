<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\PayloadValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the INT-01 webhook payload validator.
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\PayloadValidator
 */
final class PayloadValidatorTest extends TestCase
{
    public function testUnknownEventReturnsNull(): void
    {
        self::assertNull(PayloadValidator::validate('nonsense', ['userid' => 1]));
    }

    public function testEnrolmentCreatedHappyPath(): void
    {
        $out = PayloadValidator::validate('enrolment_created', [
            'userid'   => 7,
            'courseid' => 42,
            'timestart' => 1700000000,
            'timeend'   => 1800000000,
        ]);
        self::assertSame([
            'userid'    => 7,
            'courseid'  => 42,
            'timestart' => 1700000000,
            'timeend'   => 1800000000,
        ], $out);
    }

    public function testEnrolmentCreatedRejectsMissingUserId(): void
    {
        self::assertNull(PayloadValidator::validate('enrolment_created', ['courseid' => 1]));
        self::assertNull(PayloadValidator::validate('enrolment_created', [
            'userid' => 0,
            'courseid' => 1,
        ]));
    }

    public function testEnrolmentCreatedRejectsStringNonNumericUserId(): void
    {
        self::assertNull(PayloadValidator::validate('enrolment_created', [
            'userid'   => 'abc',
            'courseid' => 1,
        ]));
    }

    public function testEnrolmentCreatedAcceptsDigitString(): void
    {
        $out = PayloadValidator::validate('enrolment_created', [
            'userid'   => '7',
            'courseid' => '42',
        ]);
        self::assertSame(7, $out['userid']);
        self::assertSame(42, $out['courseid']);
        self::assertSame(0, $out['timestart']);
        self::assertSame(0, $out['timeend']);
    }

    public function testCourseCompletedRejectsNonNumericGrade(): void
    {
        self::assertNull(PayloadValidator::validate('course_completed', [
            'userid'   => 1,
            'courseid' => 2,
            'grade'    => 'A',
        ]));
    }

    public function testCourseCompletedCoercesGradeToFloat(): void
    {
        $out = PayloadValidator::validate('course_completed', [
            'userid'   => 1,
            'courseid' => 2,
            'grade'    => '87.5',
        ]);
        self::assertSame(87.5, $out['grade']);
    }

    public function testUserUpdatedStripsOverlongStrings(): void
    {
        $out = PayloadValidator::validate('user_updated', [
            'userid'   => 3,
            'username' => str_repeat('x', 300),
            'email'    => 'valid@example.com',
        ]);
        self::assertArrayNotHasKey('username', $out);
        self::assertSame('valid@example.com', $out['email']);
    }

    public function testUserUpdatedRejectsNonStringField(): void
    {
        self::assertNull(PayloadValidator::validate('user_updated', [
            'userid'   => 3,
            'username' => ['nested' => 'array'],
        ]));
    }

    public function testSupportedEventsIncludesEveryDispatcherCase(): void
    {
        self::assertSame(
            ['enrolment_created', 'enrolment_deleted', 'course_completed', 'user_updated'],
            PayloadValidator::SUPPORTED_EVENTS
        );
    }
}
