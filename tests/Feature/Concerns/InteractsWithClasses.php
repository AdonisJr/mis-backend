<?php

namespace Tests\Feature\Concerns;

use App\Models\ClassMeeting;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait InteractsWithClasses
{
    use InteractsWithRooms;

    private const CLASSES_URL = '/api/mis/classes';

    private const CLASS_KEYS = ['id', 'section_id', 'curriculum_entry_id', 'teacher_id', 'school_year_id', 'term_number', 'created_at', 'updated_at', 'section', 'subject', 'teacher', 'meetings', 'required_weekly_hours', 'scheduled_weekly_minutes', 'scheduled_weekly_hours', 'hours_status'];

    private const MEETING_KEYS = ['id', 'day', 'start_time', 'end_time', 'room_id', 'room'];

    private const TIMETABLE_MEETING_KEYS = ['id', 'day', 'start_time', 'end_time', 'room', 'class'];

    private const TIMETABLE_ROW_KEYS = ['curriculum_entry_id', 'sort_order', 'subject', 'class'];

    private const RIZAL = 'Rizal';

    private const MABINI = 'Mabini';

    private const MATH_7 = 'MATH7';

    private const ENGLISH_7 = 'ENG7';

    private const GEN_MATH = 'GENMATH';

    private Section $rizalSection;

    private CurriculumEntry $mathEntry;

    private User $mathTeacher;

    private function setUpClassFixtures(): void
    {
        $this->setUpFeeFixtures();
        $this->rizalSection = $this->createSection(['name' => self::RIZAL]);
        $this->mathEntry = $this->createJhsEntry(self::MATH_7);
        $this->mathTeacher = $this->createTeacher();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createJhsEntry(string $subjectCode, array $overrides = []): CurriculumEntry
    {
        return CurriculumEntry::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_7),
            'subject_id' => Subject::factory()->create(['code' => $subjectCode])->id,
            ...$overrides,
        ]);
    }

    private function createShsEntry(Program $program, int $termNumber): CurriculumEntry
    {
        return CurriculumEntry::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'subject_id' => Subject::factory()->shsCore()->create(['code' => self::GEN_MATH])->id,
            'program_id' => $program->id,
            'term_number' => $termNumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $classFields
     * @param  list<array<string, mixed>>  $meetingRows  day, start_time, end_time and optional room_id
     */
    private function createScheduledClass(array $classFields, array $meetingRows = []): SchoolClass
    {
        $schoolClass = SchoolClass::factory()->create($classFields);

        foreach ($meetingRows as $meetingRow) {
            ClassMeeting::factory()->create([
                'class_id' => $schoolClass->id,
                'day' => $meetingRow['day'],
                'start_minutes' => ClassMeeting::convertToMinutes($meetingRow['start_time']),
                'end_minutes' => ClassMeeting::convertToMinutes($meetingRow['end_time']),
                'room_id' => $meetingRow['room_id'] ?? null,
            ]);
        }

        return $schoolClass;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{day: int, start_time: string, end_time: string, room_id: int|null}
     */
    private function buildMeeting(array $overrides = []): array
    {
        return ['day' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'room_id' => null, ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildClassPayload(array $overrides = []): array
    {
        return [
            'section_id' => $this->rizalSection->id,
            'curriculum_entry_id' => $this->mathEntry->id,
            'teacher_id' => $this->mathTeacher->id,
            'meetings' => [$this->buildMeeting()],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postClass(array $overrides = []): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::CLASSES_URL, $this->buildClassPayload($overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putClass(int $classId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::CLASSES_URL.'/'.$classId, $payload);
    }

    private function createClassForFixtures(): SchoolClass
    {
        return $this->createScheduledClass(['section_id' => $this->rizalSection->id, 'curriculum_entry_id' => $this->mathEntry->id, 'teacher_id' => $this->mathTeacher->id]);
    }
}
