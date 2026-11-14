<?php

namespace Tests\Feature;

use App\Http\Requests\StoreStudentDiscountRequest;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Models\Term;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithDiscounts;
use Tests\TestCase;

class StudentDiscountRulesTest extends TestCase
{
    use InteractsWithDiscounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideClosedStatuses(): array
    {
        return [
            'moved' => [Enrollment::STATUS_MOVED],
            'dropped' => [Enrollment::STATUS_DROPPED],
            'transferred' => [Enrollment::STATUS_TRANSFERRED],
        ];
    }

    public function test_inactive_type_is_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $inactiveType = $this->createType();
        $inactiveType->forceFill(['is_active' => false])->save();

        $this->postAssignment($this->buildAssignmentPayload($student, $inactiveType))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::TYPE_INACTIVE_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 0);
    }

    public function test_student_without_enrollment_in_year_is_refused(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $student = Student::factory()->create();
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => Section::factory()->jhs()->create(['school_year_id' => $otherYear->id])->id]);

        $this->postAssignment($this->buildAssignmentPayload($student, $this->createType()))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['student_id' => [StoreStudentDiscountRequest::NOT_ENROLLED_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 0);
    }

    #[DataProvider('provideClosedStatuses')]
    public function test_enrollment_with_closed_status_is_refused(string $closedStatus): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, $closedStatus);

        $this->postAssignment($this->buildAssignmentPayload($student, $this->createType()))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['student_id' => [StoreStudentDiscountRequest::NOT_ENROLLED_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 0);
    }

    public function test_completed_enrollment_is_accepted(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_COMPLETED);

        $this->postAssignment($this->buildAssignmentPayload($student, $this->createType()))->assertCreated();
    }

    public function test_type_without_student_stage_is_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $shsOnlyType = $this->createType(['stages' => [GradeLevel::STAGE_SHS]]);

        $this->postAssignment($this->buildAssignmentPayload($student, $shsOnlyType))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::STAGE_NOT_ALLOWED_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 0);
    }

    public function test_shs_student_with_term_rows_matches_shs_type(): void
    {
        $student = Student::factory()->create();
        $section = Section::factory()->create(['school_year_id' => $this->schoolYear->id]);
        $firstTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-09-30']);
        $secondTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 2, 'start_date' => '2030-10-01', 'end_date' => '2031-02-28']);
        Enrollment::factory()->closed(Enrollment::STATUS_COMPLETED)->create(['student_id' => $student->id, 'section_id' => $section->id, 'term_id' => $firstTerm->id]);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => $section->id, 'term_id' => $secondTerm->id]);
        $shsType = $this->createType(['stages' => [GradeLevel::STAGE_SHS]]);

        $this->assertSame([GradeLevel::STAGE_SHS], (new StudentDiscount($this->buildAssignmentPayload($student, $shsType)))->findEnrolledStages());
        $this->postAssignment($this->buildAssignmentPayload($student, $shsType))->assertCreated()->assertJsonPath('student_discount.grade_level.stage', 'shs');

        $this->assertDatabaseCount('student_discounts', 1);
    }

    public function test_same_type_twice_in_year_is_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $discountType = $this->createType();
        $this->assignType($student, $discountType);

        $this->postAssignment($this->buildAssignmentPayload($student, $discountType))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::TYPE_ALREADY_HELD_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 1);
    }

    public function test_same_type_in_another_year_is_allowed(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $discountType = $this->createType();
        $this->assignType($student, $discountType);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => Section::factory()->jhs()->create(['school_year_id' => $otherYear->id])->id]);

        $this->postAssignment([...$this->buildAssignmentPayload($student, $discountType), 'school_year_id' => $otherYear->id])->assertCreated();

        $this->assertDatabaseCount('student_discounts', 2);
    }

    public function test_second_government_type_in_year_is_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['source' => 'government']));
        $otherGovernmentType = $this->createType(['source' => 'government']);

        $this->postAssignment($this->buildAssignmentPayload($student, $otherGovernmentType))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::GOVERNMENT_LIMIT_MESSAGE]]);

        $this->assertDatabaseCount('student_discounts', 1);
    }

    public function test_government_type_counts_when_held_type_is_inactive(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $heldType = $this->createType(['source' => 'government']);
        $this->assignType($student, $heldType);
        $heldType->forceFill(['is_active' => false])->save();

        $this->postAssignment($this->buildAssignmentPayload($student, $this->createType(['source' => 'government'])))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::GOVERNMENT_LIMIT_MESSAGE]]);
    }

    public function test_government_type_in_another_year_is_allowed(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['source' => 'government']));
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => Section::factory()->jhs()->create(['school_year_id' => $otherYear->id])->id]);

        $this->postAssignment([...$this->buildAssignmentPayload($student, $this->createType(['source' => 'government'])), 'school_year_id' => $otherYear->id])->assertCreated();
    }

    public function test_school_types_stack_with_government_type(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        foreach (['government', 'school', 'school'] as $source) {
            $this->postAssignment($this->buildAssignmentPayload($student, $this->createType(['source' => $source])))->assertCreated();
        }

        $this->assertSame(3, $student->discounts()->count());
    }

    public function test_type_error_reports_first_failure_only(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $inactiveShsType = $this->createType(['stages' => [GradeLevel::STAGE_SHS]]);
        $inactiveShsType->forceFill(['is_active' => false])->save();

        $this->postAssignment($this->buildAssignmentPayload($student, $inactiveShsType))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['discount_type_id' => [StoreStudentDiscountRequest::TYPE_INACTIVE_MESSAGE]]);
    }

    public function test_assignment_rules_wait_for_valid_ids(): void
    {
        $discountType = $this->createType();

        $unknownStudentResponse = $this->postAssignment([...$this->buildAssignmentPayload(Student::factory()->create(), $discountType), 'student_id' => self::MISSING_ID])->assertUnprocessable();

        $this->assertSame(['student_id'], array_keys($unknownStudentResponse->json('errors')));
        $this->assertNotSame([StoreStudentDiscountRequest::NOT_ENROLLED_MESSAGE], $unknownStudentResponse->json('errors.student_id'));
        $this->assertDatabaseCount('student_discounts', 0);
    }
}
