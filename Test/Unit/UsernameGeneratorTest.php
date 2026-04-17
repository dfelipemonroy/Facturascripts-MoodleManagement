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

/**
 * @since 2.0 — V2.0-ACTION-PLAN F9.2 · §1.4
 *
 * @covers \FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient::generateUsername
 */
final class UsernameGeneratorTest extends TestCase
{
    public function testEmailLocalPartBecomesUsername(): void
    {
        $c = new Contacto();
        $c->email = 'Diego.Felipe@Example.COM';
        self::assertSame('diego.felipe', MoodleClient::generateUsername($c));
    }

    public function testInvalidEmailCharsAreStripped(): void
    {
        $c = new Contacto();
        $c->email = 'juán+tag@domain.test';
        // Accents and + are stripped; underscores/dashes/dots allowed.
        $result = MoodleClient::generateUsername($c);
        self::assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $result);
        self::assertStringContainsString('jn', $result);
    }

    public function testFallbackToNombreApellidos(): void
    {
        $c = new Contacto();
        $c->email = '';
        $c->nombre = 'Ana';
        $c->apellidos = 'Pérez';
        $out = MoodleClient::generateUsername($c);
        self::assertSame('ana.prez', $out);
    }

    public function testAbsoluteFallbackOnEmpty(): void
    {
        $c = new Contacto();
        $c->email = '';
        $c->nombre = '';
        $c->apellidos = '';
        $out = MoodleClient::generateUsername($c);
        self::assertStringStartsWith('user', $out);
    }
}
