<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Plugins\MoodleManagement\Lib\Security\IpValidator;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — F9 coverage
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Security\IpValidator
 */
final class IpValidatorTest extends TestCase
{
    /**
     * @dataProvider privateIpProvider
     */
    public function testPrivateIpsRejected(string $ip): void
    {
        self::assertFalse(IpValidator::isPublicIp($ip), "expected private: $ip");
    }

    public static function privateIpProvider(): array
    {
        return [
            ['127.0.0.1'],
            ['10.0.0.1'],
            ['10.255.255.254'],
            ['172.16.0.1'],
            ['172.31.255.254'],
            ['192.168.1.1'],
            ['169.254.169.254'],    // AWS metadata
            ['100.64.0.1'],         // CGNAT
            ['224.0.0.1'],          // multicast
            ['0.0.0.0'],
            ['255.255.255.255'],
            ['::1'],
            ['fe80::1'],
            ['fc00::1'],
            ['::ffff:127.0.0.1'],   // IPv4-mapped loopback
        ];
    }

    /**
     * @dataProvider publicIpProvider
     */
    public function testPublicIpsAccepted(string $ip): void
    {
        self::assertTrue(IpValidator::isPublicIp($ip), "expected public: $ip");
    }

    public static function publicIpProvider(): array
    {
        return [
            ['8.8.8.8'],
            ['1.1.1.1'],
            ['93.184.216.34'],      // example.com
            ['2001:4860:4860::8888'], // Google DNS IPv6
        ];
    }

    public function testMalformedReturnsFalse(): void
    {
        self::assertFalse(IpValidator::isPublicIp('not-an-ip'));
        self::assertFalse(IpValidator::isPublicIp(''));
    }

    public function testAssertPublicHostThrowsOnPrivateLiteral(): void
    {
        $this->expectException(\RuntimeException::class);
        IpValidator::assertPublicHost('http://127.0.0.1/');
    }

    public function testAssertPublicHostThrowsOnLinkLocal(): void
    {
        $this->expectException(\RuntimeException::class);
        IpValidator::assertPublicHost('http://169.254.169.254/latest/meta-data/');
    }

    public function testAssertPublicHostThrowsOnEmptyUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        IpValidator::assertPublicHost('');
    }
}
