<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook;

/**
 * Schema-free payload validator for the F10.1 webhook handlers.
 *
 * Addresses audit INT-01 (2026-04-17). Each handler performed ad-hoc
 * integer casts and bail-outs when required fields were missing. The
 * validation was scattered, different handlers had slightly different
 * rules, and malformed payloads (strings where ints were expected,
 * arrays where scalars were expected) silently produced zero values
 * that sometimes matched real records.
 *
 * This class centralises the rules per event type. Each `validate*`
 * returns either a normalised assoc-array of typed values or `null`
 * when the payload is too broken to use. Handlers call the validator
 * first and short-circuit on null.
 *
 * Rules intentionally stay narrow: we only enforce the *shape* the
 * downstream handler depends on. Business validation stays in the
 * handler.
 *
 * @since 2.0 — INT-01 (2026-04-17)
 */
final class PayloadValidator
{
    /**
     * Event names understood by WebhookDispatcher. Kept here so tests
     * can assert every registered event has a matching validator.
     */
    public const SUPPORTED_EVENTS = [
        'enrolment_created',
        'enrolment_deleted',
        'course_completed',
        'user_updated',
    ];

    /**
     * Entry point keyed on event type. Returns a normalised payload or
     * null when the payload is invalid.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function validate(string $event, array $payload): ?array
    {
        switch ($event) {
            case 'enrolment_created':
                return self::validateEnrolmentCreated($payload);
            case 'enrolment_deleted':
                return self::validateEnrolmentDeleted($payload);
            case 'course_completed':
                return self::validateCourseCompleted($payload);
            case 'user_updated':
                return self::validateUserUpdated($payload);
        }
        return null;
    }

    private static function validateEnrolmentCreated(array $p): ?array
    {
        $userid = self::positiveInt($p['userid'] ?? null);
        $courseid = self::positiveInt($p['courseid'] ?? null);
        if ($userid === null || $courseid === null) {
            return null;
        }
        return [
            'userid'    => $userid,
            'courseid'  => $courseid,
            'timestart' => self::nonNegativeInt($p['timestart'] ?? 0),
            'timeend'   => self::nonNegativeInt($p['timeend'] ?? 0),
        ];
    }

    private static function validateEnrolmentDeleted(array $p): ?array
    {
        $userid = self::positiveInt($p['userid'] ?? null);
        $courseid = self::positiveInt($p['courseid'] ?? null);
        if ($userid === null || $courseid === null) {
            return null;
        }
        return [
            'userid'   => $userid,
            'courseid' => $courseid,
        ];
    }

    private static function validateCourseCompleted(array $p): ?array
    {
        $userid = self::positiveInt($p['userid'] ?? null);
        $courseid = self::positiveInt($p['courseid'] ?? null);
        if ($userid === null || $courseid === null) {
            return null;
        }

        $grade = $p['grade'] ?? null;
        if ($grade !== null && !is_numeric($grade)) {
            return null;
        }

        return [
            'userid'         => $userid,
            'courseid'       => $courseid,
            'completiondate' => self::nonNegativeInt($p['completiondate'] ?? 0),
            'grade'          => $grade === null ? null : (float) $grade,
        ];
    }

    private static function validateUserUpdated(array $p): ?array
    {
        $userid = self::positiveInt($p['userid'] ?? null);
        if ($userid === null) {
            return null;
        }
        $out = ['userid' => $userid];
        foreach (['username', 'email', 'firstname', 'lastname'] as $key) {
            if (isset($p[$key])) {
                if (!is_string($p[$key])) {
                    return null;
                }
                $trimmed = trim($p[$key]);
                if ($trimmed === '' || strlen($trimmed) > 255) {
                    continue;
                }
                $out[$key] = $trimmed;
            }
        }
        return $out;
    }

    private static function positiveInt($value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $n = (int) $value;
            return $n > 0 ? $n : null;
        }
        return null;
    }

    private static function nonNegativeInt($value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return 0;
    }

    private function __construct()
    {
    }
}
