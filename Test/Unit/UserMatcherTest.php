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
use ReflectionClass;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.5 (+ F14 bootstrap skip)
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\Matching\UserMatcher
 *
 * F14 note — Contacto built without constructor so FS DbUpdater
 * is not triggered (unit tests run without a DB).
 */
final class UserMatcherTest extends TestCase
{
    /**
     * Build a Contacto without its constructor to skip FS DB init.
     */
    private static function contact(array $props = []): Contacto
    {
        /** @var Contacto $c */
        $c = (new ReflectionClass(Contacto::class))->newInstanceWithoutConstructor();
        foreach ($props as $k => $v) {
            $c->{$k} = $v;
        }
        return $c;
    }

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
        $c = self::contact(['email' => 'diego.felipe@example.COM']);
        $match = UserMatcher::matchByEmail($c, $this->moodleUsers());
        self::assertSame(1, $match['id']);
    }

    public function testMatchByEmailNoMatch(): void
    {
        $c = self::contact(['email' => 'nobody@nowhere.test']);
        self::assertNull(UserMatcher::matchByEmail($c, $this->moodleUsers()));
    }

    public function testMatchByEmailEmptyEmailReturnsNull(): void
    {
        $c = self::contact(['email' => '']);
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
        $c = self::contact([
            'cifnif' => '12345678Z',
            'email' => 'ana@acme.test', // deliberately different user
        ]);
        $match = UserMatcher::findBestMatch($c, $this->moodleUsers());
        self::assertSame(1, $match['id'], 'Expected idnumber to win over email.');
    }

    public function testFindBestMatchFallsBackToEmail(): void
    {
        $c = self::contact([
            'cifnif' => '',
            'email' => 'ana@acme.test',
        ]);
        $match = UserMatcher::findBestMatch($c, $this->moodleUsers());
        self::assertSame(2, $match['id']);
    }
}
