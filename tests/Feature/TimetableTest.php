<?php

namespace Tests\Feature;

use App\Http\Requests\ShowSectionTimetableRequest;
use App\Http\Requests\StoreCurriculumEntryRequest;
use App\Models\ClassMeeting;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class TimetableTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    private const MONDAY_EIGHT = ['day' => 1, 'start_time' => '08:00', 'end_time' => '09:00'];

    private const TUESDAY_EIGHT = ['day' => 2, 'start_time' => '08:00', 'end_time' => '09:00'];

    private const WEDNESDAY_EIGHT = ['day' => 3, 'start_time' => '08:00', 'end_time' => '09:00'];

    private const THURSDAY_EIGHT = ['day' => 4, 'start_time' => '08:00', 'end_time' => '09:00'];

    private Program $stemProgram;

    private Section $stemSection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->stemSection = $this->createSection([
            'name' => 'STEM-A',
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'program_id' => $this->stemProgram->id,
        ]);
    }

    public function test_jhs_section_lists_curriculum_rows_with_class_or_null(): void
    {
        $this->mathEntry->update(['sort_order' => 1]);
        $englishEntry = $this->createJhsEntry(self::ENGLISH_7, ['sort_order' => 2]);
        $scienceEntry = $this->createJhsEntry('SCI7', ['sort_order' => 3]);
        $englishClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $englishEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [self::MONDAY_EIGHT],
        );

        $this->withToken($this->adminToken)->getJson($this->buildSectionTimetableUrl($this->rizalSection))
            ->assertOk()
            ->assertJsonPath('section', ['id' => $this->rizalSection->id, 'name' => self::RIZAL, 'stage' => 'jhs'])
            ->assertJsonPath('term_number', null)
            ->assertJsonCount(3, 'subjects')
            ->assertJsonStructure(['subjects' => ['*' => self::TIMETABLE_ROW_KEYS]])
            ->assertJsonPath('subjects.0.curriculum_entry_id', $this->mathEntry->id)
            ->assertJsonPath('subjects.0.class', null)
            ->assertJsonPath('subjects.1.curriculum_entry_id', $englishEntry->id)
            ->assertJsonPath('subjects.1.class.id', $englishClass->id)
            ->assertJsonPath('subjects.2.curriculum_entry_id', $scienceEntry->id)
            ->assertJsonPath('subjects.2.class', null);
    }

    public function test_shs_section_lists_only_its_program_and_term(): void
    {
        $termOneEntry = $this->createShsEntry($this->stemProgram, 1);
        $this->createSecondTermEntry($this->stemProgram);
        $abmProgram = $this->createProgram(self::ABM_PROGRAM);
        $this->createSecondTermEntry($abmProgram, 1);

        $this->withToken($this->adminToken)->getJson($this->buildSectionTimetableUrl($this->stemSection, 1))
            ->assertOk()
            ->assertJsonPath('term_number', 1)
            ->assertJsonCount(1, 'subjects')
            ->assertJsonPath('subjects.0.curriculum_entry_id', $termOneEntry->id);
    }

    public function test_shs_section_requires_a_term(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildSectionTimetableUrl($this->stemSection))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => StoreCurriculumEntryRequest::TERM_REQUIRED_MESSAGE]);
    }

    public function test_jhs_section_refuses_a_term(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildSectionTimetableUrl($this->rizalSection, 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => ShowSectionTimetableRequest::TERM_NOT_ALLOWED_MESSAGE]);
    }

    public function test_shs_section_refuses_a_term_beyond_the_semester_count(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildSectionTimetableUrl($this->stemSection, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => sprintf(StoreCurriculumEntryRequest::TERM_RANGE_MESSAGE, SchoolYear::TERM_COUNTS[SchoolYear::TERM_TYPE_SEMESTER])]);
    }

    public function test_missing_section_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::SECTIONS_URL.'/'.self::MISSING_ID.'/timetable')->assertNotFound();
    }

    public function test_teacher_timetable_with_term_includes_whole_year_classes_only_for_that_period(): void
    {
        $this->createTeacherWeek();

        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::TEACHERS_URL.'/'.$this->mathTeacher->id, 1))
            ->assertOk()
            ->assertJsonCount(2, 'meetings')
            ->assertJsonPath('meetings.0.day', 1)
            ->assertJsonPath('meetings.0.class.term_number', null)
            ->assertJsonPath('meetings.1.day', 2)
            ->assertJsonPath('meetings.1.class.term_number', 1);
    }

    public function test_teacher_timetable_without_term_returns_the_whole_year(): void
    {
        $this->createTeacherWeek();

        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::TEACHERS_URL.'/'.$this->mathTeacher->id))
            ->assertOk()
            ->assertJsonCount(3, 'meetings')
            ->assertJsonStructure(['meetings' => ['*' => self::TIMETABLE_MEETING_KEYS]]);
    }

    public function test_room_timetable_with_term_includes_whole_year_classes(): void
    {
        $room = $this->createRoom(self::LAB_A);
        $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [[...self::MONDAY_EIGHT, 'room_id' => $room->id]],
        );
        $secondTermEntry = $this->createShsEntry($this->stemProgram, 2);
        $this->createScheduledClass(
            ['section_id' => $this->stemSection->id, 'curriculum_entry_id' => $secondTermEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [[...self::TUESDAY_EIGHT, 'room_id' => $room->id]],
        );

        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::ROOMS_URL.'/'.$room->id, 1))
            ->assertOk()
            ->assertJsonCount(1, 'meetings')
            ->assertJsonPath('meetings.0.room.name', self::LAB_A)
            ->assertJsonPath('meetings.0.class.subject.code', self::MATH_7);
    }

    public function test_teacher_timetable_requires_school_year(): void
    {
        $this->withToken($this->adminToken)->getJson(self::TEACHERS_URL.'/'.$this->mathTeacher->id.'/timetable')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_room_timetable_requires_school_year(): void
    {
        $room = $this->createRoom(self::LAB_A);

        $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'/'.$room->id.'/timetable')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_timetable_refuses_a_term_beyond_the_semester_count(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::TEACHERS_URL.'/'.$this->mathTeacher->id, 4))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => sprintf(StoreCurriculumEntryRequest::TERM_RANGE_MESSAGE, SchoolYear::TERM_COUNTS[SchoolYear::TERM_TYPE_SEMESTER])]);
    }

    public function test_missing_teacher_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::TEACHERS_URL.'/'.self::MISSING_ID))->assertNotFound();
    }

    public function test_missing_room_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::ROOMS_URL.'/'.self::MISSING_ID))->assertNotFound();
    }

    public function test_meetings_are_ordered_by_day_then_start_and_carry_their_own_ids(): void
    {
        SchoolClass::factory()->count(3)->create();
        $weekClass = $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [self::TUESDAY_EIGHT, ['day' => 1, 'start_time' => '10:00', 'end_time' => '11:00'], self::MONDAY_EIGHT],
        );
        $storedMeetingIds = $weekClass->meetings()->pluck('id')->all();

        $timetableResponse = $this->withToken($this->adminToken)->getJson($this->buildPeriodUrl(self::TEACHERS_URL.'/'.$this->mathTeacher->id))->assertOk();

        $this->assertSame([[1, '08:00'], [1, '10:00'], [2, '08:00']], collect($timetableResponse->json('meetings'))->map(fn (array $meeting) => [$meeting['day'], $meeting['start_time']])->all());
        $this->assertSame($storedMeetingIds, array_column($timetableResponse->json('meetings'), 'id'));
        $this->assertSame(ClassMeeting::whereIn('id', $storedMeetingIds)->count(), count($storedMeetingIds));
        $this->assertNotContains($weekClass->id, $storedMeetingIds);
    }

    private function createTeacherWeek(): void
    {
        $this->createScheduledClass(
            ['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [self::MONDAY_EIGHT],
        );
        $this->createScheduledClass(
            ['section_id' => $this->stemSection->id, 'curriculum_entry_id' => $this->createShsEntry($this->stemProgram, 1)->id, 'teacher_id' => $this->mathTeacher->id],
            [self::TUESDAY_EIGHT],
        );
        $this->createScheduledClass(
            ['section_id' => $this->stemSection->id, 'curriculum_entry_id' => $this->createSecondTermEntry($this->stemProgram)->id, 'teacher_id' => $this->mathTeacher->id],
            [self::WEDNESDAY_EIGHT],
        );
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherSection = $this->createSection(['name' => 'Rizal Next', 'school_year_id' => $otherYear->id]);
        $otherEntry = $this->createJhsEntry(self::ENGLISH_7, ['school_year_id' => $otherYear->id]);
        $this->createScheduledClass(
            ['section_id' => $otherSection->id, 'curriculum_entry_id' => $otherEntry->id, 'teacher_id' => $this->mathTeacher->id],
            [self::THURSDAY_EIGHT],
        );
    }

    private function createSecondTermEntry(Program $program, int $termNumber = 2): CurriculumEntry
    {
        return CurriculumEntry::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'subject_id' => Subject::factory()->shsCore()->create()->id,
            'program_id' => $program->id,
            'term_number' => $termNumber,
        ]);
    }

    private function buildSectionTimetableUrl(Section $section, ?int $termNumber = null): string
    {
        return self::SECTIONS_URL.'/'.$section->id.'/timetable'.($termNumber ? '?term_number='.$termNumber : '');
    }

    private function buildPeriodUrl(string $resourcePath, ?int $termNumber = null): string
    {
        $query = ['school_year_id' => $this->schoolYear->id, ...($termNumber ? ['term_number' => $termNumber] : [])];

        return $resourcePath.'/timetable?'.http_build_query($query);
    }
}
