<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Room;
use App\Models\SchoolYear;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class SectionRoomMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const ROOM_NAME_MAX_LENGTH = 50;

    private Migration $roomMigration;

    private int $schoolYearId;

    private int $gradeLevelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roomMigration = $this->loadRoomMigration();
        $this->roomMigration->down();
        $this->schoolYearId = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030)->id;
        $this->gradeLevelId = GradeLevel::where('name', 'Grade 7')->value('id');
    }

    public function test_migration_creates_one_room_per_distinct_text_and_links_sections(): void
    {
        $firstId = $this->insertSection('A', 'Rm 101');
        $secondId = $this->insertSection('B', ' rm 101 ');
        $thirdId = $this->insertSection('C', 'RM 101');
        $emptyId = $this->insertSection('D', '');
        $blankId = $this->insertSection('E', '   ');
        $nullId = $this->insertSection('F', null);

        $this->roomMigration->up();

        $this->assertSame(1, DB::table('rooms')->count());
        $room = DB::table('rooms')->first();
        $this->assertSame('Rm 101', $room->name);
        $this->assertSame(Room::TYPE_CLASSROOM, $room->type);
        $this->assertTrue((bool) $room->is_active);
        $this->assertNull($room->building);
        $this->assertNull($room->capacity);
        foreach ([$firstId, $secondId, $thirdId] as $linkedId) {
            $this->assertSame($room->id, DB::table('sections')->where('id', $linkedId)->value('room_id'));
        }
        foreach ([$emptyId, $blankId, $nullId] as $unlinkedId) {
            $this->assertNull(DB::table('sections')->where('id', $unlinkedId)->value('room_id'));
        }
    }

    public function test_migration_drops_room_column_and_adds_room_id(): void
    {
        $this->assertTrue(Schema::hasColumn('sections', 'room'));
        $this->assertFalse(Schema::hasColumn('sections', 'room_id'));

        $this->roomMigration->up();

        $this->assertFalse(Schema::hasColumn('sections', 'room'));
        $this->assertTrue(Schema::hasColumn('sections', 'room_id'));
    }

    public function test_migration_aborts_on_room_text_over_50_characters(): void
    {
        $this->insertSection('Fine', 'Rm 101');
        $overlongId = $this->insertSection('Too Long', str_repeat('x', self::ROOM_NAME_MAX_LENGTH + 1));

        try {
            $this->roomMigration->up();
            $this->fail('The migration should abort on an over-long room text.');
        } catch (RuntimeException $abortException) {
            $this->assertStringContainsString('#'.$overlongId.' Too Long', $abortException->getMessage());
            $this->assertStringNotContainsString('Fine', $abortException->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('sections', 'room'));
        $this->assertFalse(Schema::hasColumn('sections', 'room_id'));
        $this->assertSame(0, DB::table('rooms')->count());
        $this->assertSame('Rm 101', DB::table('sections')->where('name', 'Fine')->value('room'));
    }

    public function test_migration_accepts_fifty_characters_after_trim(): void
    {
        $roomName = str_repeat('x', self::ROOM_NAME_MAX_LENGTH);
        $sectionId = $this->insertSection('Edge', '  '.$roomName.'  ');

        $this->roomMigration->up();

        $this->assertSame($roomName, DB::table('rooms')->value('name'));
        $this->assertNotNull(DB::table('sections')->where('id', $sectionId)->value('room_id'));
    }

    public function test_rollback_restores_room_text_and_keeps_rooms(): void
    {
        $sectionId = $this->insertSection('A', 'Rm 101');
        $this->roomMigration->up();
        $linkedRoomId = DB::table('sections')->where('id', $sectionId)->value('room_id');
        DB::table('rooms')->where('id', $linkedRoomId)->update(['name' => 'Renamed Room']);

        $this->roomMigration->down();

        $this->assertSame('Renamed Room', DB::table('sections')->where('id', $sectionId)->value('room'));
        $this->assertFalse(Schema::hasColumn('sections', 'room_id'));
        $this->assertSame(1, DB::table('rooms')->count());

        $this->loadRoomMigration()->up();

        $this->assertSame(1, DB::table('rooms')->count());
        $this->assertSame($linkedRoomId, DB::table('sections')->where('id', $sectionId)->value('room_id'));
    }

    private function loadRoomMigration(): Migration
    {
        // a fresh instance has an empty room cache, like a real re-run after a rollback
        return require glob(database_path('migrations/*_replace_room_with_room_id_on_sections_table.php'))[0];
    }

    private function insertSection(string $name, ?string $roomText): int
    {
        return DB::table('sections')->insertGetId([
            'school_year_id' => $this->schoolYearId,
            'grade_level_id' => $this->gradeLevelId,
            'name' => $name,
            'capacity' => 40,
            'room' => $roomText,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
