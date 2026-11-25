<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectWeightGroupMigrationTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private Migration $weightGroupMigration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->weightGroupMigration = require glob(database_path('migrations/*_add_weight_group_to_subjects_table.php'))[0];
        $this->weightGroupMigration->down();
    }

    public function test_up_backfills_the_default_group_of_each_type(): void
    {
        foreach (Subject::TYPES as $index => $type) {
            DB::table('subjects')->insert([
                'code' => 'MIG'.$index,
                'name' => 'Migrated '.$index,
                'type' => $type,
                'weekly_hours' => '4.0',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->weightGroupMigration->up();

        foreach (Subject::TYPES as $index => $type) {
            $this->assertSame(Subject::DEFAULT_WEIGHT_GROUPS[$type], DB::table('subjects')->where('code', 'MIG'.$index)->value('weight_group'));
        }
    }

    public function test_migrated_value_can_be_edited_through_the_api(): void
    {
        DB::table('subjects')->insert(['code' => self::MATH_7, 'name' => 'Mathematics 7', 'type' => Subject::TYPE_JHS_LEARNING_AREA, 'weekly_hours' => '4.0', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->weightGroupMigration->up();
        $subjectId = DB::table('subjects')->where('code', self::MATH_7)->value('id');

        $this->withToken($this->adminToken)->putJson(self::SUBJECTS_URL.'/'.$subjectId, $this->buildSubjectPayload(['weight_group' => Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE]))
            ->assertOk()
            ->assertJsonPath('subject.weight_group', Subject::WEIGHT_GROUP_JHS_MAPEH_EPP_TLE);
    }

    public function test_rollback_drops_the_column(): void
    {
        $this->assertFalse(Schema::hasColumn('subjects', 'weight_group'));

        $this->weightGroupMigration->up();
        $this->assertTrue(Schema::hasColumn('subjects', 'weight_group'));

        $this->weightGroupMigration->down();
        $this->assertFalse(Schema::hasColumn('subjects', 'weight_group'));
    }
}
