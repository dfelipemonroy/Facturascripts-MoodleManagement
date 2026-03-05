<?php

namespace FacturaScripts\Test\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use PHPUnit\Framework\TestCase;

class MoodleClientTest extends TestCase
{
    // ---- generateUsername tests ----

    public function testGenerateUsernameFromEmail(): void
    {
        $contact = new Contacto();
        $contact->email = 'john.doe@example.com';
        $contact->nombre = 'John';
        $contact->apellidos = 'Doe';

        $username = MoodleClient::generateUsername($contact);
        $this->assertSame('john.doe', $username);
    }

    public function testGenerateUsernameStripsInvalidChars(): void
    {
        $contact = new Contacto();
        $contact->email = 'José+García@example.com';

        $username = MoodleClient::generateUsername($contact);
        // Only lowercase alphanumeric, -, _, . allowed
        $this->assertMatchesRegularExpression('/^[a-z0-9\-_.]+$/', $username);
        $this->assertStringNotContainsString('+', $username);
    }

    public function testGenerateUsernameFallbackToName(): void
    {
        $contact = new Contacto();
        $contact->email = '';
        $contact->nombre = 'María';
        $contact->apellidos = 'López';

        $username = MoodleClient::generateUsername($contact);
        $this->assertNotEmpty($username);
        $this->assertMatchesRegularExpression('/^[a-z0-9\-_.]+$/', $username);
    }

    public function testGenerateUsernameFallbackWhenAllEmpty(): void
    {
        $contact = new Contacto();
        $contact->email = '';
        $contact->nombre = '';
        $contact->apellidos = '';

        $username = MoodleClient::generateUsername($contact);
        // With empty nombre/apellidos, base becomes "." which after preg_replace is "."
        // Since "." is not empty, it returns "." (edge case)
        // The "user" + time() fallback only triggers if preg_replace produces empty string
        $this->assertNotEmpty($username);
    }

    public function testGenerateUsernameWithSpecialEmailChars(): void
    {
        $contact = new Contacto();
        $contact->email = 'user_name-test.ok@domain.com';

        $username = MoodleClient::generateUsername($contact);
        $this->assertSame('user_name-test.ok', $username);
    }

    // ---- contactToMoodleUser tests ----

    public function testContactToMoodleUserBasicFields(): void
    {
        $contact = new Contacto();
        $contact->email = 'test@example.com';
        $contact->nombre = 'Juan';
        $contact->apellidos = 'Pérez';
        $contact->telefono1 = '+34600000000';
        $contact->telefono2 = '+34611111111';
        $contact->ciudad = 'Madrid';
        $contact->codpais = 'ES';
        $contact->direccion = 'Calle Mayor 1';

        $data = MoodleClient::contactToMoodleUser($contact);

        $this->assertSame('test@example.com', $data['email']);
        $this->assertSame('Juan', $data['firstname']);
        $this->assertSame('Pérez', $data['lastname']);
        $this->assertSame('+34600000000', $data['phone1']);
        $this->assertSame('+34611111111', $data['phone2']);
        $this->assertSame('Madrid', $data['city']);
        $this->assertSame('ES', $data['country']);
        $this->assertSame('Calle Mayor 1', $data['address']);
    }

    public function testContactToMoodleUserClearsPhoneticFields(): void
    {
        $contact = new Contacto();
        $contact->email = 'test@example.com';
        $contact->nombre = 'Test';

        $data = MoodleClient::contactToMoodleUser($contact);

        $this->assertSame('', $data['firstnamephonetic']);
        $this->assertSame('', $data['lastnamephonetic']);
        $this->assertSame('', $data['middlename']);
        $this->assertSame('', $data['alternatename']);
    }

    public function testContactToMoodleUserFallbackNames(): void
    {
        $contact = new Contacto();
        $contact->email = 'test@example.com';
        $contact->nombre = '';
        $contact->apellidos = '';

        $data = MoodleClient::contactToMoodleUser($contact);

        $this->assertSame('Sin nombre', $data['firstname']);
        $this->assertSame('Sin apellido', $data['lastname']);
    }

    public function testContactToMoodleUserOmitsEmptyOptionalFields(): void
    {
        $contact = new Contacto();
        $contact->email = 'test@example.com';
        $contact->nombre = 'Test';
        $contact->telefono1 = '';
        $contact->ciudad = '';

        $data = MoodleClient::contactToMoodleUser($contact);

        $this->assertArrayNotHasKey('phone1', $data);
        $this->assertArrayNotHasKey('city', $data);
    }

    public function testContactToMoodleUserCustomFields(): void
    {
        $contact = new Contacto();
        $contact->email = 'test@example.com';
        $contact->nombre = 'Test';
        $contact->cifnif = '12345678A';

        $customMap = ['dni' => 'cifnif'];
        $data = MoodleClient::contactToMoodleUser($contact, $customMap);

        $this->assertArrayHasKey('customfields', $data);
        $this->assertCount(1, $data['customfields']);
        $this->assertSame('dni', $data['customfields'][0]['type']);
        $this->assertSame('12345678A', $data['customfields'][0]['value']);
    }

    // ---- moodleUserToContact tests ----

    public function testMoodleUserToContactBasicFields(): void
    {
        $contact = new Contacto();
        $moodleUser = [
            'email' => 'moodle@example.com',
            'firstname' => 'Moodle',
            'lastname' => 'User',
            'phone1' => '+1234567890',
            'phone2' => '+0987654321',
            'city' => 'Barcelona',
            'country' => 'ES',
            'lang' => 'es',
            'address' => 'Av. Diagonal 100',
        ];

        MoodleClient::moodleUserToContact($contact, $moodleUser);

        $this->assertSame('moodle@example.com', $contact->email);
        $this->assertSame('Moodle', $contact->nombre);
        $this->assertSame('User', $contact->apellidos);
        $this->assertSame('+1234567890', $contact->telefono1);
        $this->assertSame('+0987654321', $contact->telefono2);
        $this->assertSame('Barcelona', $contact->ciudad);
        $this->assertSame('ES', $contact->codpais);
        $this->assertSame('es', $contact->langcode);
        $this->assertSame('Av. Diagonal 100', $contact->direccion);
    }

    public function testMoodleUserToContactSkipsEmptyFields(): void
    {
        $contact = new Contacto();
        $contact->nombre = 'Original';
        $contact->email = 'original@test.com';

        $moodleUser = [
            'email' => 'new@test.com',
            'firstname' => '',
            'lastname' => '',
        ];

        MoodleClient::moodleUserToContact($contact, $moodleUser);

        $this->assertSame('new@test.com', $contact->email);
        $this->assertSame('Original', $contact->nombre); // Not overwritten
    }

    public function testMoodleUserToContactCustomFields(): void
    {
        $contact = new Contacto();
        $moodleUser = [
            'email' => 'test@example.com',
            'firstname' => 'Test',
            'customfields' => [
                ['shortname' => 'dni', 'value' => '99999999Z'],
                ['shortname' => 'unknown_field', 'value' => 'ignored'],
            ],
        ];

        $customMap = ['dni' => 'cifnif'];
        MoodleClient::moodleUserToContact($contact, $moodleUser, $customMap);

        $this->assertSame('99999999Z', $contact->cifnif);
    }

    // ---- resolveConflict tests ----

    public function testResolveConflictFsWins(): void
    {
        $result = MoodleClient::resolveConflict('fs_wins', '2025-01-01', '2025-12-31');
        $this->assertSame('fs', $result);
    }

    public function testResolveConflictMoodleWins(): void
    {
        $result = MoodleClient::resolveConflict('moodle_wins', '2025-12-31', '2025-01-01');
        $this->assertSame('moodle', $result);
    }

    public function testResolveConflictNewestWinsFsNewer(): void
    {
        $result = MoodleClient::resolveConflict('newest_wins', '2025-06-15 10:00:00', '2025-06-14 10:00:00');
        $this->assertSame('fs', $result);
    }

    public function testResolveConflictNewestWinsMoodleNewer(): void
    {
        $result = MoodleClient::resolveConflict('newest_wins', '2025-06-14 10:00:00', '2025-06-15 10:00:00');
        $this->assertSame('moodle', $result);
    }

    public function testResolveConflictNewestWinsWithUnixTimestamp(): void
    {
        // Moodle often returns unix timestamps
        $moodleTs = strtotime('2025-06-15 12:00:00');
        $result = MoodleClient::resolveConflict('newest_wins', '2025-06-15 10:00:00', (string)$moodleTs);
        $this->assertSame('moodle', $result);
    }

    public function testResolveConflictNewestWinsBothEmpty(): void
    {
        $result = MoodleClient::resolveConflict('newest_wins', null, null);
        $this->assertSame('fs', $result);
    }

    public function testResolveConflictNewestWinsFsEmpty(): void
    {
        $result = MoodleClient::resolveConflict('newest_wins', null, '2025-06-15');
        $this->assertSame('moodle', $result);
    }

    public function testResolveConflictNewestWinsMoodleEmpty(): void
    {
        $result = MoodleClient::resolveConflict('newest_wins', '2025-06-15', null);
        $this->assertSame('fs', $result);
    }

    public function testResolveConflictUnknownPriority(): void
    {
        $result = MoodleClient::resolveConflict('invalid', '2025-01-01', '2025-01-01');
        $this->assertSame('conflict', $result);
    }

    // ---- applySiteInfo tests ----

    public function testApplySiteInfo(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();

        $siteInfo = [
            'version' => '2024051700',
            'release' => '4.4.1 (Build: 20240715)',
            'sitename' => 'Test Moodle',
            'lang' => 'es',
            'username' => 'wsadmin',
            'functions' => array_fill(0, 150, ['name' => 'test']),
        ];

        MoodleClient::applySiteInfo($instance, $siteInfo);

        $this->assertSame('2024051700', $instance->moodle_version);
        $this->assertSame('4.4.1 (Build: 20240715)', $instance->moodle_release);
        $this->assertSame('Test Moodle', $instance->site_name);
        $this->assertSame('es', $instance->lang);
        $this->assertSame('wsadmin', $instance->service_username);
        $this->assertSame(150, $instance->available_functions);
        $this->assertSame('active', $instance->status);
        $this->assertEmpty($instance->last_error);
        $this->assertNotEmpty($instance->last_check);
    }

    // ---- applyError tests ----

    public function testApplyError(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();
        $instance->status = 'active';

        MoodleClient::applyError($instance, [
            'exception' => 'curl_error',
            'message' => 'Connection timed out',
        ]);

        $this->assertSame('unreachable', $instance->status);
        $this->assertSame('Connection timed out', $instance->last_error);
        $this->assertNotEmpty($instance->last_check);
    }

    public function testApplyErrorWithExceptionOnly(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();

        MoodleClient::applyError($instance, ['exception' => 'http_error']);

        $this->assertSame('unreachable', $instance->status);
        $this->assertSame('http_error', $instance->last_error);
    }
}
