<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnrollmentEnrollTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENTS_URL = '/api/mis/enrollments';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2031 = ['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const FIRST_TERM = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_TERM = ['term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31'];

    private const ENROLLMENT_KEYS = ['id', 'student_id', 'section_id', 'term_id', 'status', 'enrolled_at', 'ended_at', 'remarks', 'created_at', 'updated_at', 'student', 'section', 'term'];

    private const NULLABLE_COLUMNS = ['term_id', 'ended_at', 'remarks', 'created_at', 'updated_at'];

    private SchoolYear $schoolYear;

    private Term $firstTerm;

    private Term $secondTerm;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->secondTerm = Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->adminToken = $this->createTokenFor(User::factory()->admin()->create());
    }

    public function test_enrollments_table_has_expected_columns_and_restrict_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumns('enrollments', ['id', 'student_id', 'section_id', 'term_id', 'status', 'enrolled_at', 'ended_at', 'remarks', 'created_at', 'updated_at']));

        $nullableByColumn = collect(Schema::getColumns('enrollments'))->pluck('nullable', 'name');
        $this->assertCount(10, $nullableByColumn);

        foreach ($nullableByColumn as $columnName => $isNullable) {
            $this->assertSame(in_array($columnName, self::NULLABLE_COLUMNS, true), $isNullable, $columnName);
        }

        $foreignKeys = collect(Schema::getForeignKeys('enrollments'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();
        $this->assertEquals([
            'student_id' => ['students', 'restrict'],
            'section_id' => ['sections', 'restrict'],
            'term_id' => ['terms', 'restrict'],
        ], $foreignKeys);

        $enrollment = Enrollment::factory()->shs()->create();

        foreach (['students' => $enrollment->student_id, 'sections' => $enrollment->section_id, 'terms' => $enrollment->term_id] as $parentTable => $parentId) {
            $this->assertThrows(fn () => DB::table($parentTable)->where('id', $parentId)->delete(), QueryException::class);
        }
    }

    public function test_admin_enrolls_jhs_student_without_term(): void
    {
        $this->travelTo('2030-09-15');
        $student = $this->createStudent(1);
        $section = $this->createJhsSection('A');

        $enrollResponse = $this->postEnrollment($student, $section)
            ->assertCreated()
            ->assertJsonPath('enrollment.student_id', $student->id)
            ->assertJsonPath('enrollment.section_id', $section->id)
            ->assertJsonPath('enrollment.term_id', null)
            ->assertJsonPath('enrollment.status', Enrollment::STATUS_ENROLLED)
            ->assertJsonPath('enrollment.enrolled_at', '2030-09-15')
            ->assertJsonPath('enrollment.ended_at', null)
            ->assertJsonPath('enrollment.remarks', null)
            ->assertJsonPath('enrollment.term', null)
            ->assertJsonPath('enrollment.student.lrn', $student->lrn)
            ->assertJsonPath('enrollment.section.name', 'A')
            ->assertJsonPath('enrollment.section.program', null)
            ->assertJsonPath('enrollment.section.grade_level.stage', GradeLevel::STAGE_JHS);

        $this->assertSame(self::ENROLLMENT_KEYS, array_keys($enrollResponse->json('enrollment')));
        $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'section_id' => $section->id, 'term_id' => null, 'status' => Enrollment::STATUS_ENROLLED]);
    }

    public function test_jhs_enroll_with_term_returns_422(): void
    {
        $this->postEnrollment($this->createStudent(1), $this->createJhsSection('A'), ['term_id' => $this->firstTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_NOT_ALLOWED_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_shs_enroll_requires_term(): void
    {
        $student = $this->createStudent(1);
        $section = $this->createShsSection('STEM 11-A');

        foreach ([[], ['term_id' => null]] as $termFields) {
            $this->postEnrollment($student, $section, $termFields)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_REQUIRED_MESSAGE]);
        }

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_college_enroll_requires_term(): void
    {
        $section = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'C']);

        $this->postEnrollment($this->createStudent(1), $section)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_REQUIRED_MESSAGE]);
        $this->postEnrollment($this->createStudent(2), $section, ['term_id' => $this->firstTerm->id])
            ->assertCreated()
            ->assertJsonPath('enrollment.section.grade_level.stage', GradeLevel::STAGE_COLLEGE);
    }

    public function test_term_outside_section_school_year_returns_422(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherTerm = Term::factory()->create(['school_year_id' => $otherYear->id, 'term_number' => 1, 'start_date' => '2031-06-01', 'end_date' => '2031-10-31']);

        $this->postEnrollment($this->createStudent(1), $this->createShsSection('STEM 11-A'), ['term_id' => $otherTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_YEAR_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_shs_enroll_with_term_of_section_year_succeeds(): void
    {
        $student = $this->createStudent(1);
        $section = $this->createShsSection('STEM 11-A');

        $this->postEnrollment($student, $section, ['term_id' => (string) $this->secondTerm->id])
            ->assertCreated()
            ->assertJsonPath('enrollment.term_id', $this->secondTerm->id)
            ->assertJsonPath('enrollment.term', ['id' => $this->secondTerm->id, 'term_number' => 2, 'label' => '2nd Semester'])
            ->assertJsonPath('enrollment.section.program.code', $section->program->code);
    }

    public function test_enroll_stores_given_enrolled_at_and_remarks(): void
    {
        $student = $this->createStudent(1);

        $this->postEnrollment($student, $this->createJhsSection('A'), ['enrolled_at' => '2030-07-01', 'remarks' => 'Late enrollee'])
            ->assertCreated()
            ->assertJsonPath('enrollment.enrolled_at', '2030-07-01')
            ->assertJsonPath('enrollment.remarks', 'Late enrollee');

        $this->assertDatabaseHas('enrollments', ['student_id' => $student->id, 'remarks' => 'Late enrollee']);
    }

    public function test_enroll_rejects_invalid_fields(): void
    {
        $student = $this->createStudent(1);
        $section = $this->createJhsSection('A');
        $validPayload = ['student_id' => $student->id, 'section_id' => $section->id];

        foreach (['student_id', 'section_id'] as $requiredField) {
            $this->assertRejectedField(array_diff_key($validPayload, [$requiredField => true]), $requiredField);
            $this->assertRejectedField([...$validPayload, $requiredField => null], $requiredField);
        }
        foreach (['student_id', 'section_id', 'term_id'] as $idField) {
            foreach ([self::MISSING_ID, '05', true, 'abc', 1.5] as $invalidId) {
                $this->assertRejectedField([...$validPayload, $idField => $invalidId], $idField);
            }
        }
        foreach (['2030-02-30', '09/15/2030', 'not-a-date', 20300915] as $invalidDate) {
            $this->assertRejectedField([...$validPayload, 'enrolled_at' => $invalidDate], 'enrolled_at');
        }
        $this->assertRejectedField([...$validPayload, 'remarks' => Str::repeat('r', 1001)], 'remarks');
        $this->assertRejectedField([...$validPayload, 'remarks' => ['x']], 'remarks');
        $this->assertDatabaseCount('enrollments', 0);

        $this->postEnrollment($student, $section, ['remarks' => Str::repeat('r', 1000)])->assertCreated();
    }

    public function test_second_enrolled_record_in_same_term_returns_422(): void
    {
        $student = $this->createStudent(1);
        $this->postEnrollment($student, $this->createShsSection('STEM 11-A'), ['term_id' => $this->firstTerm->id])->assertCreated();

        $this->postEnrollment($student, $this->createShsSection('STEM 11-B'), ['term_id' => $this->firstTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => EnrollmentController::ALREADY_ENROLLED_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_enrolled_record_in_other_term_is_allowed(): void
    {
        $student = $this->createStudent(1);
        $section = $this->createShsSection('STEM 11-A');
        $this->postEnrollment($student, $section, ['term_id' => $this->firstTerm->id])->assertCreated();

        $this->postEnrollment($student, $section, ['term_id' => $this->secondTerm->id])->assertCreated();

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_second_jhs_record_in_same_year_returns_422(): void
    {
        $student = $this->createStudent(1);
        $this->postEnrollment($student, $this->createJhsSection('A'))->assertCreated();

        $this->postEnrollment($student, $this->createJhsSection('B'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => EnrollmentController::ALREADY_ENROLLED_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_jhs_record_blocks_shs_term_record_in_same_year(): void
    {
        $student = $this->createStudent(1);
        $this->postEnrollment($student, $this->createJhsSection('A'))->assertCreated();

        $this->postEnrollment($student, $this->createShsSection('STEM 11-A'), ['term_id' => $this->firstTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => EnrollmentController::ALREADY_ENROLLED_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_shs_term_record_blocks_jhs_record_in_same_year(): void
    {
        $student = $this->createStudent(1);
        $this->postEnrollment($student, $this->createShsSection('STEM 11-A'), ['term_id' => $this->firstTerm->id])->assertCreated();

        $this->postEnrollment($student, $this->createJhsSection('A'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id' => EnrollmentController::ALREADY_ENROLLED_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_closed_record_does_not_block_new_enrollment(): void
    {
        $student = $this->createStudent(1);
        $firstSection = $this->createJhsSection('A');
        Enrollment::factory()->closed(Enrollment::STATUS_DROPPED)->create(['student_id' => $student->id, 'section_id' => $firstSection->id]);

        $this->postEnrollment($student, $this->createJhsSection('B'))->assertCreated();

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_enrolled_record_in_other_school_year_does_not_block(): void
    {
        $student = $this->createStudent(1);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherYearSection = Section::factory()->jhs()->create(['school_year_id' => $otherYear->id, 'name' => 'A']);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => $otherYearSection->id]);

        $this->postEnrollment($student, $this->createJhsSection('A'))->assertCreated();

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_enroll_into_full_section_returns_422(): void
    {
        $section = $this->createJhsSection('A', ['capacity' => 2]);

        $this->postEnrollment($this->createStudent(1), $section)->assertCreated();
        $this->postEnrollment($this->createStudent(2), $section)->assertCreated();
        $this->postEnrollment($this->createStudent(3), $section)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::SECTION_FULL_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_capacity_ignores_closed_records_and_other_terms(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 1]);
        Enrollment::factory()->closed(Enrollment::STATUS_DROPPED)->create(['student_id' => $this->createStudent(1)->id, 'section_id' => $section->id, 'term_id' => $this->firstTerm->id]);
        Enrollment::factory()->create(['student_id' => $this->createStudent(2)->id, 'section_id' => $section->id, 'term_id' => $this->secondTerm->id]);

        $this->postEnrollment($this->createStudent(3), $section, ['term_id' => $this->firstTerm->id])->assertCreated();
        $this->postEnrollment($this->createStudent(4), $section, ['term_id' => $this->firstTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::SECTION_FULL_MESSAGE]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRejectedField(array $payload, string $rejectedField): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ENROLLMENTS_URL, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$rejectedField]);
    }

    /**
     * @param  array<string, mixed>  $extraFields
     */
    private function postEnrollment(Student $student, Section $section, array $extraFields = []): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->postJson(self::ENROLLMENTS_URL, ['student_id' => $student->id, 'section_id' => $section->id, ...$extraFields]);
    }

    private function createStudent(int $number): Student
    {
        return Student::factory()->create(['lrn' => '13651234000'.$number]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createJhsSection(string $name, array $sectionFields = []): Section
    {
        return Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name, ...$sectionFields]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createShsSection(string $name, array $sectionFields = []): Section
    {
        return Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name, ...$sectionFields]);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
