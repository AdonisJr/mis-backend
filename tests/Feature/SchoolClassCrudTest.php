<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateSchoolClassRequest;
use App\Models\ClassMeeting;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class SchoolClassCrudTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    private const CLASS_COLUMNS = ['id', 'section_id', 'curriculum_entry_id', 'teacher_id', 'school_year_id', 'term_number', 'created_at', 'updated_at'];

    private const MEETING_COLUMNS = ['id', 'class_id', 'day', 'start_minutes', 'end_minutes', 'room_id', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    public function test_classes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('classes', self::CLASS_COLUMNS));

        $classColumns = collect(Schema::getColumns('classes'))->keyBy('name');
        foreach (['section_id', 'curriculum_entry_id', 'teacher_id', 'school_year_id'] as $requiredColumn) {
            $this->assertFalse($classColumns[$requiredColumn]['nullable'], $requiredColumn.' must not be nullable');
        }
        $this->assertTrue($classColumns['term_number']['nullable']);
    }

    public function test_class_meetings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('class_meetings', self::MEETING_COLUMNS));

        $meetingColumns = collect(Schema::getColumns('class_meetings'))->keyBy('name');
        foreach (['class_id', 'day', 'start_minutes', 'end_minutes'] as $requiredColumn) {
            $this->assertFalse($meetingColumns[$requiredColumn]['nullable'], $requiredColumn.' must not be nullable');
        }
        $this->assertTrue($meetingColumns['room_id']['nullable']);
    }

    public function test_foreign_keys_restrict_and_cascade_as_planned(): void
    {
        $this->assertEquals([
            'section_id' => ['sections', 'restrict'],
            'curriculum_entry_id' => ['curriculum_entries', 'restrict'],
            'teacher_id' => ['users', 'restrict'],
            'school_year_id' => ['school_years', 'restrict'],
        ], $this->mapForeignKeys('classes'));
        $this->assertEquals([
            'class_id' => ['classes', 'cascade'],
            'room_id' => ['rooms', 'restrict'],
        ], $this->mapForeignKeys('class_meetings'));

        $schoolClass = SchoolClass::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('sections')->where('id', $schoolClass->section_id)->delete();
    }

    public function test_admin_creates_jhs_class_with_meetings(): void
    {
        $payload = $this->buildClassPayload(['meetings' => [
            $this->buildMeeting(['day' => 3, 'start_time' => '10:00', 'end_time' => '11:00']),
            $this->buildMeeting(['day' => 1, 'start_time' => '09:00', 'end_time' => '10:00']),
            $this->buildMeeting(['day' => 1, 'start_time' => '08:00', 'end_time' => '09:00']),
        ]]);

        $this->withToken($this->adminToken)->postJson(self::CLASSES_URL, $payload)
            ->assertCreated()
            ->assertJsonStructure(['class' => self::CLASS_KEYS])
            ->assertJsonCount(16, 'class')
            ->assertJsonPath('class.section_id', $this->rizalSection->id)
            ->assertJsonPath('class.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('class.term_number', null)
            ->assertJsonPath('class.section', ['id' => $this->rizalSection->id, 'name' => self::RIZAL])
            ->assertJsonPath('class.subject.code', self::MATH_7)
            ->assertJsonPath('class.teacher.id', $this->mathTeacher->id)
            ->assertJsonPath('class.meetings.0.start_time', '08:00')
            ->assertJsonPath('class.meetings.1.start_time', '09:00')
            ->assertJsonPath('class.meetings.2.day', 3);

        $this->assertDatabaseCount('classes', 1);
        $this->assertDatabaseCount('class_meetings', 3);
    }

    public function test_create_shs_class_gets_entry_term(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $stemSection = $this->createSection([
            'name' => 'STEM-A',
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'program_id' => $stemProgram->id,
        ]);
        $shsEntry = $this->createShsEntry($stemProgram, 2);

        $this->withToken($this->adminToken)
            ->postJson(self::CLASSES_URL, $this->buildClassPayload(['section_id' => $stemSection->id, 'curriculum_entry_id' => $shsEntry->id]))
            ->assertCreated()
            ->assertJsonPath('class.term_number', 2)
            ->assertJsonPath('class.subject.code', self::GEN_MATH);
    }

    public function test_create_allows_zero_meetings(): void
    {
        $this->withToken($this->adminToken)->postJson(self::CLASSES_URL, $this->buildClassPayload(['meetings' => []]))
            ->assertCreated()
            ->assertJsonPath('class.meetings', []);

        $this->assertDatabaseCount('class_meetings', 0);
    }

    public function test_admin_shows_class(): void
    {
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [$this->buildMeeting()],
        );

        $this->withToken($this->adminToken)->getJson(self::CLASSES_URL.'/'.$schoolClass->id)
            ->assertOk()
            ->assertJsonStructure(['class' => self::CLASS_KEYS])
            ->assertJsonStructure(['class' => ['meetings' => ['*' => self::MEETING_KEYS]]])
            ->assertJsonPath('class.id', $schoolClass->id);
    }

    public function test_show_missing_class_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::CLASSES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_update_changes_teacher_and_replaces_meetings(): void
    {
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [$this->buildMeeting()],
        );
        $oldMeetingIds = $schoolClass->meetings()->pluck('id')->all();
        $newTeacher = $this->createTeacher();

        $this->withToken($this->adminToken)
            ->putJson(self::CLASSES_URL.'/'.$schoolClass->id, [
                'teacher_id' => $newTeacher->id,
                'meetings' => [$this->buildMeeting(['day' => 2, 'start_time' => '13:00', 'end_time' => '14:00'])],
            ])
            ->assertOk()
            ->assertJsonPath('class.teacher_id', $newTeacher->id)
            ->assertJsonCount(1, 'class.meetings')
            ->assertJsonPath('class.meetings.0.day', 2);

        $this->assertDatabaseCount('class_meetings', 1);
        $this->assertDatabaseMissing('class_meetings', ['id' => $oldMeetingIds[0]]);
    }

    public function test_update_accepts_same_section_and_entry_ids(): void
    {
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
        );
        $payload = [
            'section_id' => (string) $this->rizalSection->id,
            'curriculum_entry_id' => $this->mathEntry->id,
            'teacher_id' => $this->mathTeacher->id,
            'meetings' => [],
        ];

        $this->withToken($this->adminToken)->putJson(self::CLASSES_URL.'/'.$schoolClass->id, $payload)->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLockedKeys(): array
    {
        return ['section_id' => ['section_id'], 'curriculum_entry_id' => ['curriculum_entry_id']];
    }

    #[DataProvider('provideLockedKeys')]
    public function test_update_rejects_changed_key(string $keyField): void
    {
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
        );
        $otherSection = $this->createSection(['name' => self::MABINI]);
        $otherEntry = $this->createJhsEntry(self::ENGLISH_7);
        $changedValues = ['section_id' => $otherSection->id, 'curriculum_entry_id' => $otherEntry->id];

        $this->withToken($this->adminToken)
            ->putJson(self::CLASSES_URL.'/'.$schoolClass->id, [...$this->buildClassPayload(), $keyField => $changedValues[$keyField]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$keyField])
            ->assertJsonPath("errors.{$keyField}.0", UpdateSchoolClassRequest::KEY_LOCKED_MESSAGE);
    }

    public function test_update_missing_class_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::CLASSES_URL.'/'.self::MISSING_ID, ['teacher_id' => $this->mathTeacher->id, 'meetings' => []])
            ->assertNotFound();
    }

    public function test_delete_cascades_meetings(): void
    {
        $schoolClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [$this->buildMeeting(), $this->buildMeeting(['day' => 2])],
        );

        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.$schoolClass->id)->assertNoContent();

        $this->assertDatabaseCount('classes', 0);
        $this->assertSame(0, ClassMeeting::count());
    }

    public function test_delete_missing_class_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::CLASSES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_create_ignores_unvalidated_keys(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $this->withToken($this->adminToken)
            ->postJson(self::CLASSES_URL, $this->buildClassPayload(['school_year_id' => $otherYear->id, 'term_number' => 2, 'id' => 500]))
            ->assertCreated()
            ->assertJsonPath('class.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('class.term_number', null)
            ->assertJsonPath('class.id', fn (int $classId) => $classId !== 500);
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function mapForeignKeys(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();
    }
}
