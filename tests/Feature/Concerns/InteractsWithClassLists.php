<?php

namespace Tests\Feature\Concerns;

use App\Http\Controllers\ClassListController;
use App\Http\Requests\ListTeacherClassesRequest;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;

trait InteractsWithClassLists
{
    use InteractsWithClasses;

    private const MY_CLASSES_URL = '/api/mis/my-classes';

    private const ADVISORY_URL = '/api/mis/my-advisory-section';

    private const CLASS_LIST_CLASS_KEYS = ['id', 'school_year', 'term_number', 'term_label', 'section', 'grade_level', 'program', 'subject', 'teacher', 'meetings', 'student_count'];

    private const ROSTER_ROW_KEYS = ['enrollment_id', 'student_id', 'last_name', 'first_name', 'middle_name', 'lrn', 'gender', 'status'];

    private const GUARDIAN_KEYS = ['guardian_name', 'guardian_relationship', 'guardian_contact'];

    private const SECTION_RESOURCE_KEYS = ['id', 'school_year_id', 'grade_level_id', 'program_id', 'name', 'capacity', 'room_id', 'adviser_id', 'created_at', 'updated_at', 'grade_level', 'program', 'room', 'adviser', 'enrolled_count'];

    private const FOREIGN_TEACHER_BODY = ['message' => ListTeacherClassesRequest::FOREIGN_TEACHER_MESSAGE];

    private const CLASS_NOT_PERMITTED_BODY = ['message' => ClassListController::CLASS_NOT_PERMITTED_MESSAGE];

    // these names never collide with StudentFactory names (Magsaysay/Quezon/Osmena/Laurel, Ligaya/Dakila/Tala/Bayani)
    private const AQUINO = 'Aquino';

    private const BONIFACIO = 'Bonifacio';

    private const RAMOS = 'Ramos';

    private const JOSE = 'Jose';

    private const MARIA = 'Maria';

    private Program $stemProgram;

    private Section $stemSection;

    private function setUpClassListFixtures(): void
    {
        $this->setUpClassFixtures();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->stemSection = $this->createSection([
            'name' => 'STEM-A',
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'program_id' => $this->stemProgram->id,
        ]);
    }

    private function createTerm(int $termNumber): Term
    {
        return Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => $termNumber]);
    }

    private function createShsClass(User $teacher, int $termNumber, ?Section $section = null): SchoolClass
    {
        $entry = CurriculumEntry::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_11),
            'subject_id' => Subject::factory()->shsCore()->create()->id,
            'program_id' => $this->stemProgram->id,
            'term_number' => $termNumber,
        ]);

        return $this->createScheduledClass([
            'section_id' => ($section ?? $this->stemSection)->id,
            'curriculum_entry_id' => $entry->id,
            'teacher_id' => $teacher->id,
        ], [$this->buildMeeting()]);
    }

    /**
     * @param  array<string, mixed>  $classFields
     */
    private function createJhsClass(User $teacher, array $classFields = []): SchoolClass
    {
        return $this->createScheduledClass([
            'section_id' => $this->rizalSection->id,
            'curriculum_entry_id' => $this->createJhsEntry('SUB'.fake()->unique()->numerify('####'))->id,
            'teacher_id' => $teacher->id,
            ...$classFields,
        ], [$this->buildMeeting()]);
    }

    /**
     * @param  array<string, mixed>  $studentFields
     * @param  array<string, mixed>  $enrollmentFields  section_id is required; term_id and status are optional
     */
    private function enrollStudent(array $studentFields, array $enrollmentFields): Enrollment
    {
        return $this->enrollExistingStudent(Student::factory()->create($studentFields), $enrollmentFields);
    }

    /**
     * @param  array<string, mixed>  $enrollmentFields  section_id is required; term_id and status are optional
     */
    private function enrollExistingStudent(Student $student, array $enrollmentFields): Enrollment
    {
        $status = $enrollmentFields['status'] ?? Enrollment::STATUS_ENROLLED;
        $enrollmentFactory = $status === Enrollment::STATUS_ENROLLED ? Enrollment::factory() : Enrollment::factory()->closed($status);

        return $enrollmentFactory->create(['student_id' => $student->id, 'term_id' => null, ...$enrollmentFields, 'status' => $status]);
    }

    private function rosterUrl(int $classId): string
    {
        return self::CLASSES_URL.'/'.$classId.'/roster';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildMyClassesUrl(array $query): string
    {
        return self::MY_CLASSES_URL.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function buildAdvisoryUrl(array $query): string
    {
        return self::ADVISORY_URL.'?'.http_build_query($query);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function pluckLastNames(array $rows): array
    {
        return array_column($rows, 'last_name');
    }
}
