<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Security;

/**
 * SSRF gate: validates that a target host resolves to a public
 * routable IP before the outbound request fires.
 *
 * Blocks the common SSRF sinks enumerated in OWASP 2025:
 *   - RFC1918 private ranges (10/8, 172.16/12, 192.168/16)
 *   - Loopback (127.0.0.0/8, ::1)
 *   - Link-local (169.254.0.0/16, fe80::/10) — AWS metadata sits here
 *   - Carrier-grade NAT (100.64.0.0/10)
 *   - Multicast (224.0.0.0/4) and reserved (240.0.0.0/4)
 *   - Unspecified (0.0.0.0, ::)
 *   - IPv4-mapped IPv6 that would otherwise bypass the filter
 *
 * Usage:
 *   IpValidator::assertPublicHost($url);  // throws if private
 *
 * @since 2.0 — V2.0-ACTION-PLAN F7.1 · §4.4
 */
final class IpValidator
{
    /** @var string[] Private ranges expressed as CIDR (IPv4). */
    private const IPV4_PRIVATE_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /** @var string[] Private ranges expressed as CIDR (IPv6). */
    private const IPV6_PRIVATE_PREFIXES = [
        '::/128',     // unspecified
        '::1/128',    // loopback
        '::ffff:0:0/96', // IPv4-mapped — caught separately
        'fc00::/7',   // unique local
        'fe80::/10',  // link-local
        'ff00::/8',   // multicast
    ];

    /**
     * Throws RuntimeException if any IP that the URL's host resolves
     * to is private. Returns the hostname on success.
     *
     * @throws \RuntimeException
     */
    public static function assertPublicHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            throw new \RuntimeException('ssrf-guard: url has no host');
        }

        // Numeric literal bypass check: reject if the "hostname"
        // is itself a private IP literal before we even resolve.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new \RuntimeException('ssrf-guard: private ip literal');
            }
            return $host;
        }

        $ips = @gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            // IPv6 resolution (gethostbynamel is IPv4-only). Fall back
            // to dns_get_record for AAAA.
            $ipv6 = @dns_get_record($host, DNS_AAAA);
            $ips = [];
            foreach ($ipv6 as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            throw new \RuntimeException('ssrf-guard: could not resolve host ' . $host);
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('ssrf-guard: host resolves to private ip ' . $ip);
            }
        }
        return $host;
    }

    /**
     * True if the given IP is a public, routable address. Works
     * with both IPv4 and IPv6 literals.
     */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::IPV4_PRIVATE_RANGES as $cidr) {
                if (self::ipv4InCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Map IPv4-mapped IPv6 (::ffff:a.b.c.d) back and recurse.
            if (strpos($ip, '::ffff:') === 0) {
                $maybeV4 = substr($ip, 7);
                if (filter_var($maybeV4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return self::isPublicIp($maybeV4);
                }
            }
            foreach (self::IPV6_PRIVATE_PREFIXES as $cidr) {
                if (self::ipv6InCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private static function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) {
            return false;
        }
        $mask = (int) $mask;
        if ($mask <= 0) {
            return true;
        }
        $maskLong = -1 << (32 - $mask);
        return ($ipLong & $maskLong) === ($subLong & $maskLong);
    }

    private static function ipv6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipBin = inet_pton($ip);
        $subBin = inet_pton($subnet);
        if ($ipBin === false || $subBin === false) {
            return false;
        }
        $mask = (int) $mask;
        $bytes = intdiv($mask, 8);
        $bits = $mask % 8;
        if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }
        $b1 = ord($ipBin[$bytes] ?? "\0");
        $b2 = ord($subBin[$bytes] ?? "\0");
        $maskByte = (0xFF << (8 - $bits)) & 0xFF;
        return ($b1 & $maskByte) === ($b2 & $maskByte);
    }

    private function __construct()
    {
    }
}
