<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAssessmentRequest;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Support\AssessmentCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAssessments;
use Tests\TestCase;

class AssessmentEligibilityTest extends TestCase
{
    use InteractsWithAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
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

    public function test_create_requires_valid_ids(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $validPayload = $this->buildAssessmentPayload($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        $snapshot = $this->snapshotAssessmentTables();

        $this->postAssessment([])->assertUnprocessable()->assertJsonValidationErrors(['student_id', 'school_year_id', 'payment_plan_id']);

        foreach (array_keys($validPayload) as $idKey) {
            foreach (['05', true, self::MISSING_ID] as $invalidId) {
                $this->postAssessment([...$validPayload, $idKey => $invalidId])->assertUnprocessable()->assertJsonValidationErrors([$idKey]);
            }
        }

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_student_without_eligible_enrollment_in_year_is_refused(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $student = Student::factory()->create();
        $otherSection = Section::factory()->jhs()->create(['school_year_id' => $otherYear->id]);
        Enrollment::factory()->create(['student_id' => $student->id, 'section_id' => $otherSection->id]);
        $snapshot = $this->snapshotAssessmentTables();

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan()))
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', AssessmentCalculator::NOT_ENROLLED_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    #[DataProvider('provideClosedStatuses')]
    public function test_closed_enrollment_is_refused(string $closedStatus): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, $closedStatus);

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan()))
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', AssessmentCalculator::NOT_ENROLLED_MESSAGE);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_completed_enrollment_is_accepted(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS, Enrollment::STATUS_COMPLETED);

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan()))->assertCreated();
    }

    public function test_college_only_student_is_refused(): void
    {
        $student = Student::factory()->create();
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id]);
        Enrollment::factory()->college()->create(['student_id' => $student->id, 'section_id' => $collegeSection->id]);

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan()))
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', AssessmentCalculator::NOT_BILLABLE_MESSAGE);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_enrolled_row_wins_over_completed_row_for_grade_level(): void
    {
        $this->createTwoJhsSchedules();
        $student = Student::factory()->create();
        $this->enrollInGradeLevel($student, self::GRADE_8, Enrollment::STATUS_ENROLLED);
        $this->enrollInGradeLevel($student, self::GRADE_7, Enrollment::STATUS_COMPLETED);

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame(self::GRADE_8, $assessment['grade_level']['name']);
        $this->assertSame('2000.00', $assessment['gross_total']);
    }

    public function test_latest_completed_row_is_used_without_enrolled_row(): void
    {
        $this->createTwoJhsSchedules();
        $student = Student::factory()->create();
        $this->enrollInGradeLevel($student, self::GRADE_7, Enrollment::STATUS_COMPLETED);
        $this->enrollInGradeLevel($student, self::GRADE_8, Enrollment::STATUS_COMPLETED);

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame(self::GRADE_8, $assessment['grade_level']['name']);
    }

    public function test_missing_fee_schedule_is_refused(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan()))
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', AssessmentCalculator::NO_FEE_SCHEDULE_MESSAGE);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_inactive_plan_is_refused(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        $this->postAssessment($this->buildAssessmentPayload($student, $this->createCashPlan(['is_active' => false])))
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_plan_id.0', AssessmentCalculator::PLAN_INACTIVE_MESSAGE);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_plan_of_another_year_is_refused(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $otherYearPlan = PaymentPlan::factory()->create(['school_year_id' => SchoolYear::factory()->create(self::SCHOOL_YEAR_2031)->id]);

        $this->postAssessment($this->buildAssessmentPayload($student, $otherYearPlan))
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment_plan_id.0', AssessmentCalculator::PLAN_OTHER_YEAR_MESSAGE);

        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_student_with_current_assessment_cannot_be_assessed_again(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $payload = $this->buildAssessmentPayload($student, $this->createCashPlan());
        $this->postAssessment($payload)->assertCreated();
        $snapshot = $this->snapshotAssessmentTables();

        $this->postAssessment($payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.student_id.0', StoreAssessmentRequest::ALREADY_ASSESSED_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_preview_ignores_the_current_assessment_rule(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $payload = $this->buildAssessmentPayload($student, $this->createCashPlan());
        $this->postAssessment($payload)->assertCreated();

        $this->postPreview($payload)->assertOk()->assertJsonPath('assessment.net_total', '1000.00');

        $this->assertSame(1, Assessment::count());
    }

    public function test_eligibility_waits_for_valid_ids(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        $this->postAssessment([...$this->buildAssessmentPayload($student, $this->createCashPlan()), 'payment_plan_id' => self::MISSING_ID])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_plan_id'])
            ->assertJsonMissingValidationErrors(['student_id']);
    }

    private function createTwoJhsSchedules(): void
    {
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $this->createSchedule($this->buildKeys(self::GRADE_7), [[$tuitionItem, '1000.00']]);
        $this->createSchedule($this->buildKeys(self::GRADE_8), [[$tuitionItem, '2000.00']]);
    }

    private function enrollInGradeLevel(Student $student, string $gradeLevelName, string $status): Enrollment
    {
        $section = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'grade_level_id' => $this->findGradeLevelId($gradeLevelName)]);
        $enrollmentFactory = $status === Enrollment::STATUS_ENROLLED ? Enrollment::factory() : Enrollment::factory()->closed($status);

        return $enrollmentFactory->create(['student_id' => $student->id, 'section_id' => $section->id]);
    }
}
