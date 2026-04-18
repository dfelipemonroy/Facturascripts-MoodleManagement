<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Logger;

/**
 * PII masking helpers for log lines.
 *
 * Problem: the original ExpiryNotifier + other workers logged full
 * contact email addresses, full names, phone numbers, etc. Under
 * typical retention policies (≥30 days) that accumulates personal
 * data in operations logs — a GDPR liability.
 *
 * Usage:
 *   Tools::log()->info('expiry-notify', [
 *       'email' => PiiMasker::email($contact->email),
 *       'name'  => PiiMasker::name($contact->nombre . ' ' . $contact->apellidos),
 *   ]);
 *
 * Formats chosen:
 *   email  "juan.perez@example.com" -> "j***@e***.com"
 *   name   "Juan Pérez"              -> "J*** P***"
 *   phone  "+34 600 123 456"         -> "+34 *** *** 456"
 *   dni    "12345678Z"               -> "1234****Z"
 *
 * @since 2.0 — V2.0-ACTION-PLAN F8.5 · §2.5
 */
final class PiiMasker
{
    /**
     * Mask an email, preserving the first character of local-part
     * and the first character of the domain, plus the TLD.
     */
    public static function email(?string $email): string
    {
        $email = trim((string) $email);
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = $local !== '' ? $local[0] . '***' : '***';
        $parts = explode('.', $domain);
        if (count($parts) < 2) {
            return $local . '@***';
        }
        // Keep the last TWO segments as the suffix when the domain
        // has 3+ labels (covers ccTLDs like co.uk, com.au, com.br).
        // Fall back to the last single label otherwise.
        $count = count($parts);
        $suffixLabels = $count >= 3 ? 2 : 1;
        $suffix = implode('.', array_slice($parts, -$suffixLabels));
        $mainSegments = array_slice($parts, 0, $count - $suffixLabels);
        $mainJoined = implode('.', $mainSegments);
        $main = $mainJoined !== '' ? $mainJoined[0] . '***' : '***';
        return $local . '@' . $main . '.' . $suffix;
    }

    /**
     * Mask each whitespace-separated token to the first character +
     * `***`. "Juan Pérez" -> "J*** P***".
     */
    public static function name(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        $masked = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $first = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
            $masked[] = $first . '***';
        }
        return implode(' ', $masked);
    }

    /**
     * Keep the last 3 digits and country code; mask the middle.
     */
    public static function phone(?string $phone): string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }
        // Split leading '+' from rest
        $lead = '';
        if ($phone[0] === '+') {
            $lead = '+';
            $phone = substr($phone, 1);
        }
        $digits = preg_replace('/\D/', '', $phone) ?: '';
        $len = strlen($digits);
        if ($len <= 3) {
            return $lead . str_repeat('*', $len);
        }
        $last3 = substr($digits, -3);
        $cc = $len > 10 ? substr($digits, 0, 2) . ' ' : '';
        return trim($lead . $cc . '*** *** ' . $last3);
    }

    /**
     * Mask a DNI / CIF: keep the first 4 and last character.
     */
    public static function dni(?string $dni): string
    {
        $dni = trim((string) $dni);
        $len = strlen($dni);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        $prefix = substr($dni, 0, 4);
        $suffix = substr($dni, -1);
        $stars = str_repeat('*', max(0, $len - 5));
        return $prefix . $stars . $suffix;
    }

    private function __construct()
    {
    }
}
