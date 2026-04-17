<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Test\Plugins\MoodleManagement\Unit;

use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.2 · §1.4 (+ F14 bootstrap skip)
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::generateUsername
 *
 * F14 note — `new Contacto()` triggers FS DbUpdater which requires a
 * configured DB. In pure-logic unit tests we bypass the constructor
 * via Reflection so `generateUsername` can read the public
 * properties without FS core wanting to CREATE TABLE.
 */
final class UsernameGeneratorTest extends TestCase
{
    /**
     * Build a Contacto instance without running its constructor,
     * so FS DbUpdater does not reach for a non-existent DB.
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

    public function testEmailLocalPartBecomesUsername(): void
    {
        $c = self::contact(['email' => 'Diego.Felipe@Example.COM']);
        self::assertSame('diego.felipe', MoodleClient::generateUsername($c));
    }

    public function testInvalidEmailCharsAreStripped(): void
    {
        $c = self::contact(['email' => 'juán+tag@domain.test']);
        // Accents and + are stripped; a-z/0-9/_/-/. survive.
        // Input "juán+tag" -> "juntag" (á and + removed).
        $result = MoodleClient::generateUsername($c);
        self::assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $result);
        self::assertSame('juntag', $result);
    }

    public function testFallbackToNombreApellidos(): void
    {
        $c = self::contact([
            'email'     => '',
            'nombre'    => 'Ana',
            'apellidos' => 'Pérez',
        ]);
        $out = MoodleClient::generateUsername($c);
        self::assertSame('ana.prez', $out);
    }

    public function testAbsoluteFallbackOnEmpty(): void
    {
        $c = self::contact([
            'email'     => '',
            'nombre'    => '',
            'apellidos' => '',
        ]);
        $out = MoodleClient::generateUsername($c);
        self::assertStringStartsWith('user', $out);
    }
}
