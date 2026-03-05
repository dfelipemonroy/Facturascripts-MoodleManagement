<?php

namespace FacturaScripts\Test\Plugins\MoodleManagement\Model;

use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCohort;
use PHPUnit\Framework\TestCase;

class MoodleCohortTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertSame('moodle_cohorts', MoodleCohort::tableName());
    }

    public function testPrimaryColumn(): void
    {
        $this->assertSame('id', MoodleCohort::primaryColumn());
    }

    public function testPrimaryDescriptionColumn(): void
    {
        $cohort = new MoodleCohort();
        $this->assertSame('name', $cohort->primaryDescriptionColumn());
    }

    public function testClearSetsDefaults(): void
    {
        $cohort = new MoodleCohort();
        $cohort->clear();

        $this->assertSame('synced', $cohort->source);
        $this->assertSame(0, $cohort->member_count);
        $this->assertNotEmpty($cohort->creation_date);
    }
}
