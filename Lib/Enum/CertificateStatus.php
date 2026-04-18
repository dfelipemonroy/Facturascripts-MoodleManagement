<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Enum;

/**
 * Allowed values for `moodle_certificates.status`.
 *
 * Fase 3 F3.8 uses these constants to pick a row colour in
 * ListMoodleCertificate (success/warning/danger).
 *
 * @since 2.0
 */
final class CertificateStatus
{
    /** Issued and currently valid. */
    public const ACTIVE = 'active';

    /** Issued but revoked by an administrator. */
    public const REVOKED = 'revoked';

    /** Valid-until date has passed. */
    public const EXPIRED = 'expired';

    /** Pending issuance (e.g. waiting on Moodle badge completion). */
    public const PENDING = 'pending';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::ACTIVE,
            self::REVOKED,
            self::EXPIRED,
            self::PENDING,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Maps a status to a Bootstrap contextual class (used by
     * ListMoodleCertificate colour override in F3.8).
     */
    public static function bootstrapContext(string $status): string
    {
        switch ($status) {
            case self::ACTIVE:
                return 'success';
            case self::PENDING:
                return 'warning';
            case self::REVOKED:
            case self::EXPIRED:
                return 'danger';
            default:
                return 'secondary';
        }
    }

    private function __construct()
    {
    }
}
