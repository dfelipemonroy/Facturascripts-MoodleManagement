<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Matching;

use FacturaScripts\Core\Model\Contacto;

/**
 * FS-contact ↔ Moodle-user matching strategies, extracted from
 * MoodleUserSync and MoodleImportWizard so both controllers (and
 * future ones) share a single, tested matcher.
 *
 * Strategy order (best to worst match):
 *   1. ID-number exact     Contacto->cifnif or Contacto->email matches Moodle user->idnumber
 *   2. Email exact case-insensitive
 *   3. Username exact case-insensitive
 *
 * Every method returns the matching Moodle user array or null.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.2 · §1.8
 */
final class UserMatcher
{
    /**
     * Find by normalised lowercase email. Safe against operator
     * whitespace.
     *
     * @param array[] $moodleUsers Array of Moodle user dicts
     *                             (shape returned by core_user_get_users_by_field).
     */
    public static function matchByEmail(Contacto $contact, array $moodleUsers): ?array
    {
        if (empty($contact->email)) {
            return null;
        }
        $needle = strtolower(trim((string) $contact->email));
        foreach ($moodleUsers as $user) {
            if (!isset($user['email'])) {
                continue;
            }
            if (strtolower(trim((string) $user['email'])) === $needle) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Find by Moodle username (case-insensitive). Useful when FS
     * stores the expected username explicitly in MoodleUserMap.
     */
    public static function matchByUsername(string $username, array $moodleUsers): ?array
    {
        if ($username === '') {
            return null;
        }
        $needle = strtolower(trim($username));
        foreach ($moodleUsers as $user) {
            if (!isset($user['username'])) {
                continue;
            }
            if (strtolower(trim((string) $user['username'])) === $needle) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Find by Moodle idnumber (a custom ID typically loaded with
     * the FS `cifnif`).
     */
    public static function matchByIdnumber(string $idnumber, array $moodleUsers): ?array
    {
        if ($idnumber === '') {
            return null;
        }
        foreach ($moodleUsers as $user) {
            if (!isset($user['idnumber'])) {
                continue;
            }
            if ((string) $user['idnumber'] === $idnumber) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Best-effort chain: idnumber(cifnif) → email → username.
     * Returns first non-null strategy match.
     */
    public static function findBestMatch(Contacto $contact, array $moodleUsers, string $username = ''): ?array
    {
        $cifnif = (string) ($contact->cifnif ?? '');
        if ($cifnif !== '') {
            $match = self::matchByIdnumber($cifnif, $moodleUsers);
            if ($match !== null) {
                return $match;
            }
        }
        $match = self::matchByEmail($contact, $moodleUsers);
        if ($match !== null) {
            return $match;
        }
        if ($username !== '') {
            return self::matchByUsername($username, $moodleUsers);
        }
        return null;
    }

    private function __construct()
    {
    }
}
