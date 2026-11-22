<?php

namespace Tests\Feature;

use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class ClassConflictTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    private const MONDAY_EIGHT = ['day' => 1, 'start_time' => '08:00', 'end_time' => '09:00'];

    private const TUESDAY_EIGHT = ['day' => 2, 'start_time' => '08:00', 'end_time' => '09:00'];

    private Section $mabiniSection;

    private CurriculumEntry $englishEntry;

    private User $englishTeacher;

    private Program $stemProgram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
        $this->mabiniSection = $this->createSection(['name' => self::MABINI]);
        $this->englishEntry = $this->createJhsEntry(self::ENGLISH_7);
        $this->englishTeacher = $this->createTeacher();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
    }

    public function test_teacher_conflict_is_rejected_with_the_clashing_class_in_the_message(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT]);

        $this->postClass(['section_id' => $this->mabiniSection->id, 'curriculum_entry_id' => $this->englishEntry->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0' => 'Teacher conflict: Rizal MATH7 meets on Monday 08:00-09:00.']);

        $this->assertDatabaseCount('classes', 1);
        $this->assertDatabaseCount('class_meetings', 1);
    }

    public function test_room_conflict_is_rejected(): void
    {
        $sharedRoom = $this->createRoom(self::LAB_A);
        $this->createMathClassMeeting([[...self::MONDAY_EIGHT, 'room_id' => $sharedRoom->id]], $this->englishTeacher);

        $this->postClass([
            'section_id' => $this->mabiniSection->id,
            'curriculum_entry_id' => $this->englishEntry->id,
            'meetings' => [$this->buildMeeting(['start_time' => '08:30', 'end_time' => '09:30', 'room_id' => $sharedRoom->id])],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0' => 'Room conflict: Rizal MATH7 meets on Monday 08:00-09:00.']);

        $this->assertDatabaseCount('classes', 1);
        $this->assertDatabaseCount('class_meetings', 1);
    }

    public function test_section_conflict_is_rejected(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT], $this->englishTeacher);

        $this->postClass(['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->englishEntry->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0' => 'Section conflict: Rizal MATH7 meets on Monday 08:00-09:00.']);

        $this->assertDatabaseCount('classes', 1);
        $this->assertDatabaseCount('class_meetings', 1);
    }

    public function test_touching_meetings_do_not_conflict(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT]);

        $this->postClass([
            'section_id' => $this->rizalSection->id,
            'curriculum_entry_id' => $this->englishEntry->id,
            'meetings' => [$this->buildMeeting(['start_time' => '09:00', 'end_time' => '10:00'])],
        ])->assertCreated();
    }

    public function test_same_slot_on_another_day_does_not_conflict(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT]);

        $this->postClass([
            'section_id' => $this->rizalSection->id,
            'curriculum_entry_id' => $this->englishEntry->id,
            'meetings' => [$this->buildMeeting(['day' => 2])],
        ])->assertCreated();
    }

    public function test_same_shs_term_conflicts(): void
    {
        $firstEntry = $this->createShsEntry($this->stemProgram, 1);
        $stemA = $this->createStemSection('STEM-A');
        $stemB = $this->createStemSection('STEM-B');
        $this->createScheduledClass(['section_id' => $stemA->id, 'curriculum_entry_id' => $firstEntry->id, 'teacher_id' => $this->mathTeacher->id], [self::MONDAY_EIGHT]);

        $this->postClass(['section_id' => $stemB->id, 'curriculum_entry_id' => $firstEntry->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0' => 'Teacher conflict: STEM-A GENMATH meets on Monday 08:00-09:00.']);
    }

    public function test_different_shs_terms_do_not_conflict(): void
    {
        $firstEntry = $this->createShsEntry($this->stemProgram, 1);
        $secondEntry = $this->createSecondTermEntry();
        $stemA = $this->createStemSection('STEM-A');
        $this->createScheduledClass(['section_id' => $stemA->id, 'curriculum_entry_id' => $firstEntry->id, 'teacher_id' => $this->mathTeacher->id], [self::MONDAY_EIGHT]);

        $this->postClass(['section_id' => $stemA->id, 'curriculum_entry_id' => $secondEntry->id])->assertCreated()->assertJsonPath('class.term_number', 2);
    }

    public function test_existing_jhs_class_conflicts_with_new_shs_class(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT]);
        $shsEntry = $this->createShsEntry($this->stemProgram, 1);
        $stemA = $this->createStemSection('STEM-A');

        $this->postClass(['section_id' => $stemA->id, 'curriculum_entry_id' => $shsEntry->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.0']);
    }

    public function test_existing_shs_class_conflicts_with_new_jhs_class(): void
    {
        $shsEntry = $this->createShsEntry($this->stemProgram, 1);
        $stemA = $this->createStemSection('STEM-A');
        $this->createScheduledClass(['section_id' => $stemA->id, 'curriculum_entry_id' => $shsEntry->id, 'teacher_id' => $this->mathTeacher->id], [self::MONDAY_EIGHT]);

        $this->postClass()->assertUnprocessable()->assertJsonValidationErrors(['meetings.0']);
    }

    public function test_other_school_year_does_not_conflict(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherSection = $this->createSection(['name' => 'Rizal Next', 'school_year_id' => $otherYear->id]);
        $otherEntry = $this->createJhsEntry(self::ENGLISH_7, ['school_year_id' => $otherYear->id]);
        $this->createScheduledClass(['section_id' => $otherSection->id, 'curriculum_entry_id' => $otherEntry->id, 'teacher_id' => $this->mathTeacher->id], [self::MONDAY_EIGHT]);

        $this->postClass()->assertCreated();
    }

    public function test_resave_with_same_meetings_succeeds(): void
    {
        $schoolClass = $this->createMathClassMeeting([self::MONDAY_EIGHT]);

        $this->putClass($schoolClass->id, ['teacher_id' => $this->mathTeacher->id, 'meetings' => [$this->buildMeeting()]])->assertOk();
    }

    public function test_teacher_change_rechecks_every_meeting(): void
    {
        $schoolClass = $this->createMathClassMeeting([self::MONDAY_EIGHT, self::TUESDAY_EIGHT]);
        $this->createEnglishClassMeeting(self::TUESDAY_EIGHT);

        $this->putClass($schoolClass->id, [
            'teacher_id' => $this->englishTeacher->id,
            'meetings' => [$this->buildMeeting(), $this->buildMeeting(['day' => 2])],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['meetings.1'])
            ->assertJsonMissingValidationErrors(['meetings.0']);
    }

    public function test_teacher_change_to_a_free_teacher_succeeds(): void
    {
        $schoolClass = $this->createMathClassMeeting([self::MONDAY_EIGHT]);
        $freeTeacher = $this->createTeacher();

        $this->putClass($schoolClass->id, ['teacher_id' => $freeTeacher->id, 'meetings' => [$this->buildMeeting()]])
            ->assertOk()
            ->assertJsonPath('class.teacher_id', $freeTeacher->id);
    }

    public function test_conflict_on_update_writes_nothing(): void
    {
        $schoolClass = $this->createMathClassMeeting([self::MONDAY_EIGHT]);
        $this->createEnglishClassMeeting(self::TUESDAY_EIGHT);
        $storedMeetingIds = $schoolClass->meetings()->pluck('id')->all();

        $this->putClass($schoolClass->id, [
            'teacher_id' => $this->englishTeacher->id,
            'meetings' => [$this->buildMeeting(['day' => 3]), $this->buildMeeting(['day' => 2])],
        ])->assertUnprocessable()->assertJsonValidationErrors(['meetings.1']);

        $this->assertSame($this->mathTeacher->id, $schoolClass->fresh()->teacher_id);
        $this->assertSame($storedMeetingIds, $schoolClass->meetings()->pluck('id')->all());
    }

    public function test_null_rooms_never_conflict(): void
    {
        $this->createMathClassMeeting([self::MONDAY_EIGHT]);

        $this->postClass([
            'section_id' => $this->mabiniSection->id,
            'curriculum_entry_id' => $this->englishEntry->id,
            'teacher_id' => $this->englishTeacher->id,
        ])->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $meetingRows
     */
    private function createMathClassMeeting(array $meetingRows, ?User $teacher = null): SchoolClass
    {
        return $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => ($teacher ?? $this->mathTeacher)->id],
            $meetingRows,
        );
    }

    /**
     * @param  array<string, mixed>  $meetingRow
     */
    private function createEnglishClassMeeting(array $meetingRow): SchoolClass
    {
        return $this->createScheduledClass(
            ['section_id' => $this->mabiniSection->id, 'curriculum_entry_id' => $this->englishEntry->id, 'teacher_id' => $this->englishTeacher->id],
            [$meetingRow],
        );
    }

    private function createStemSection(string $name): Section
    {
        return $this->createSection([
            'name' => $name,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'program_id' => $this->stemProgram->id,
        ]);
    }

    private function createSecondTermEntry(): CurriculumEntry
    {
        return CurriculumEntry::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'subject_id' => Subject::factory()->shsCore()->create()->id,
            'program_id' => $this->stemProgram->id,
            'term_number' => 2,
        ]);
    }
}
