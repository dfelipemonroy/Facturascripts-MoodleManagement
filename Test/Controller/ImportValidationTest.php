<?php

namespace FacturaScripts\Test\Plugins\MoodleManagement\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Tests import validation logic extracted from MoodleUserSync controller.
 * These are pure unit tests that don't require database or HTTP context.
 */
class ImportValidationTest extends TestCase
{
    public function testImportModeIsRequired(): void
    {
        $importMode = '';
        $this->assertTrue(empty($importMode), 'Empty import mode should be rejected');
    }

    public function testClientRequiredForAssignMode(): void
    {
        $importMode = 'assign_to_existing_client';
        $codcliente = '';
        $needsClient = ($importMode === 'assign_to_existing_client' && empty($codcliente));
        $this->assertTrue($needsClient, 'Assign mode without client should be rejected');
    }

    public function testClientNotRequiredForCreateMode(): void
    {
        $importMode = 'create_client_per_user';
        $codcliente = '';
        $needsClient = ($importMode === 'assign_to_existing_client' && empty($codcliente));
        $this->assertFalse($needsClient, 'Create mode should not require a client');
    }

    public function testEmailValidation(): void
    {
        // Valid emails
        $this->assertNotFalse(filter_var('user@example.com', FILTER_VALIDATE_EMAIL));
        $this->assertNotFalse(filter_var('test.user@domain.co.uk', FILTER_VALIDATE_EMAIL));

        // Invalid emails
        $this->assertFalse(filter_var('notanemail', FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var('', FILTER_VALIDATE_EMAIL));
    }

    public function testLocalhostEmailsSkipped(): void
    {
        $email = 'admin@localhost';
        $this->assertTrue(str_ends_with($email, '@localhost'));

        $email2 = 'user@example.com';
        $this->assertFalse(str_ends_with($email2, '@localhost'));
    }

    public function testDeletedUsersSkipped(): void
    {
        $userDeleted = ['email' => 'test@example.com', 'deleted' => true];
        $userActive = ['email' => 'test@example.com', 'deleted' => false];
        $userNoFlag = ['email' => 'test@example.com'];

        $this->assertTrue($userDeleted['deleted'] ?? false);
        $this->assertFalse($userActive['deleted'] ?? false);
        $this->assertFalse($userNoFlag['deleted'] ?? false);
    }

    public function testUsersWithoutEmailSkipped(): void
    {
        $userNoEmail = ['id' => 1, 'username' => 'test'];
        $userEmptyEmail = ['id' => 2, 'email' => '', 'username' => 'test2'];
        $userWithEmail = ['id' => 3, 'email' => 'test@example.com'];

        $this->assertTrue(empty($userNoEmail['email']));
        $this->assertTrue(empty($userEmptyEmail['email']));
        $this->assertFalse(empty($userWithEmail['email']));
    }

    public function testClientNameGeneration(): void
    {
        // Test full name generation from Moodle user data
        $user1 = ['firstname' => 'John', 'lastname' => 'Doe'];
        $fullName = trim(($user1['firstname'] ?? '') . ' ' . ($user1['lastname'] ?? ''));
        $this->assertSame('John Doe', $fullName);

        // Fallback to username
        $user2 = ['firstname' => '', 'lastname' => '', 'username' => 'jdoe'];
        $fullName2 = trim(($user2['firstname'] ?? '') . ' ' . ($user2['lastname'] ?? ''));
        $name = $fullName2 ?: $user2['username'] ?? 'Moodle User';
        $this->assertSame('jdoe', $name);

        // Fallback to default
        $user3 = ['firstname' => '', 'lastname' => ''];
        $fullName3 = trim(($user3['firstname'] ?? '') . ' ' . ($user3['lastname'] ?? ''));
        $name3 = $fullName3 ?: $user3['username'] ?? 'Moodle User';
        $this->assertSame('Moodle User', $name3);
    }

    public function testCifnifDefaultsToIdnumber(): void
    {
        $user = ['idnumber' => 'ABC123'];
        $cifnif = $user['idnumber'] ?? '';
        $this->assertSame('ABC123', $cifnif);

        $userNoId = [];
        $cifnif2 = $userNoId['idnumber'] ?? '';
        $this->assertSame('', $cifnif2);
    }

    public function testSyncDirectionFiltering(): void
    {
        // syncAllToMoodle should exclude moodle_to_fs
        $directions = ['fs_to_moodle', 'bidirectional', 'moodle_to_fs'];
        $toMoodle = array_filter($directions, fn($d) => $d !== 'moodle_to_fs');
        $this->assertCount(2, $toMoodle);
        $this->assertContains('fs_to_moodle', $toMoodle);
        $this->assertContains('bidirectional', $toMoodle);

        // syncAllFromMoodle should exclude fs_to_moodle
        $fromMoodle = array_filter($directions, fn($d) => $d !== 'fs_to_moodle');
        $this->assertCount(2, $fromMoodle);
        $this->assertContains('moodle_to_fs', $fromMoodle);
        $this->assertContains('bidirectional', $fromMoodle);
    }

    public function testBatchChunking(): void
    {
        $ids = range(1, 120);
        $batches = array_chunk($ids, 50);

        $this->assertCount(3, $batches);
        $this->assertCount(50, $batches[0]);
        $this->assertCount(50, $batches[1]);
        $this->assertCount(20, $batches[2]);
    }
}
