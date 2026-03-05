<?php

namespace FacturaScripts\Test\Plugins\MoodleManagement\Model;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;
use PHPUnit\Framework\TestCase;

class MoodleUserMapTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('moodle_user_map', MoodleUserMap::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertSame('id', MoodleUserMap::primaryColumn());
    }

    public function testPrimaryDescriptionColumn(): void
    {
        $map = new MoodleUserMap();
        $this->assertSame('moodle_username', $map->primaryDescriptionColumn());
    }

    public function testClearSetsDefaults(): void
    {
        $map = new MoodleUserMap();
        $map->clear();

        $this->assertSame('bidirectional', $map->sync_direction);
        $this->assertSame('newest_wins', $map->sync_priority);
        $this->assertNotEmpty($map->creation_date);
    }

    public function testGetEffectivePriorityUsesOwnPriority(): void
    {
        $map = new MoodleUserMap();
        $map->clear();
        $map->sync_priority = 'fs_wins';

        $this->assertSame('fs_wins', $map->getEffectivePriority());
    }
}
