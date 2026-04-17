<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Lib\Matching\UserMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.5
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Matching\UserMatcher
 */
final class UserMatcherTest extends TestCase
{
    /** Shared dataset used by every test. */
    private function moodleUsers(): array
    {
        return [
            ['id' => 1, 'email' => 'Diego.Felipe@Example.com', 'username' => 'diego.felipe', 'idnumber' => '12345678Z'],
            ['id' => 2, 'email' => 'ana@acme.test', 'username' => 'ana', 'idnumber' => ''],
            ['id' => 3, 'email' => '', 'username' => 'mariadb_admin', 'idnumber' => ''],
        ];
    }

    public function testMatchByEmailCaseInsensitive(): void
    {
        $c = new Contacto();
        $c->email = 'diego.felipe@example.COM';
        $match = UserMatcher::matchByEmail($c, $this->moodleUsers());
        self::assertSame(1, $match['id']);
    }

    public function testMatchByEmailNoMatch(): void
    {
        $c = new Contacto();
        $c->email = 'nobody@nowhere.test';
        self::assertNull(UserMatcher::matchByEmail($c, $this->moodleUsers()));
    }

    public function testMatchByEmailEmptyEmailReturnsNull(): void
    {
        $c = new Contacto();
        $c->email = '';
        self::assertNull(UserMatcher::matchByEmail($c, $this->moodleUsers()));
    }

    public function testMatchByUsername(): void
    {
        $match = UserMatcher::matchByUsername('ana', $this->moodleUsers());
        self::assertSame(2, $match['id']);
    }

    public function testMatchByIdnumber(): void
    {
        $match = UserMatcher::matchByIdnumber('12345678Z', $this->moodleUsers());
        self::assertSame(1, $match['id']);
    }

    public function testFindBestMatchPrefersIdnumberOverEmail(): void
    {
        $c = new Contacto();
        $c->cifnif = '12345678Z';
        $c->email = 'ana@acme.test'; // deliberately points to a different user.
        $match = UserMatcher::findBestMatch($c, $this->moodleUsers());
        self::assertSame(1, $match['id'], 'Expected idnumber to win over email.');
    }

    public function testFindBestMatchFallsBackToEmail(): void
    {
        $c = new Contacto();
        $c->cifnif = '';
        $c->email = 'ana@acme.test';
        $match = UserMatcher::findBestMatch($c, $this->moodleUsers());
        self::assertSame(2, $match['id']);
    }
}
