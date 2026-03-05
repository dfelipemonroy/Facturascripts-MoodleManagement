<?php

namespace FacturaScripts\Test\Plugins\MoodleManagement\Model;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use PHPUnit\Framework\TestCase;

class MoodleInstanceTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('moodle_instances', MoodleInstance::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertSame('id', MoodleInstance::primaryColumn());
    }

    public function testPrimaryDescriptionColumn(): void
    {
        $instance = new MoodleInstance();
        $this->assertSame('name', $instance->primaryDescriptionColumn());
    }

    public function testClearSetsDefaults(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();

        $this->assertSame('active', $instance->status);
        $this->assertSame('production', $instance->environment);
        $this->assertSame('newest_wins', $instance->default_sync_priority);
        $this->assertNotEmpty($instance->creation_date);
    }

    public function testGetCustomFieldsMapEmpty(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();
        $instance->custom_fields_map = '';

        $this->assertSame([], $instance->getCustomFieldsMap());
    }

    public function testGetCustomFieldsMapValid(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();
        $instance->custom_fields_map = json_encode(['dni' => 'cifnif', 'nif' => 'cifnif']);

        $map = $instance->getCustomFieldsMap();
        $this->assertCount(2, $map);
        $this->assertSame('cifnif', $map['dni']);
    }

    public function testGetCustomFieldsMapInvalidJson(): void
    {
        $instance = new MoodleInstance();
        $instance->clear();
        $instance->custom_fields_map = 'not json';

        $this->assertSame([], $instance->getCustomFieldsMap());
    }
}
