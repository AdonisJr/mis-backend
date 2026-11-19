<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentDiscountController;
use App\Http\Requests\StoreStudentDiscountRequest;
use App\Models\Assessment;
use App\Models\DiscountType;
use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\GradeLevel;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\StudentDiscount;
use App\Support\AssessmentCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithAssessments;
use Tests\TestCase;

class StudentDiscountReassessTest extends TestCase
{
    use InteractsWithAssessments;
    use RefreshDatabase;

    private Student $student;

    private PaymentPlan $paymentPlan;

    private FeeSchedule $feeSchedule;

    private DiscountType $discountType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->feeSchedule = $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $this->student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->paymentPlan = $this->createCashPlan();
        $this->discountType = $this->createType(['name' => self::SIBLING_TYPE, 'value' => '500.00']);
    }

    public function test_add_discount_with_current_assessment_returns_422(): void
    {
        $this->assessStudent($this->student, $this->paymentPlan);
        $snapshot = $this->snapshotAssessmentTables();

        $this->postAssignment($this->buildAssignmentPayload($this->student, $this->discountType))
            ->assertUnprocessable()
            ->assertJsonPath('errors.assessment.0', StudentDiscountController::CURRENT_ASSESSMENT_MESSAGE);

        $this->assertDatabaseCount('student_discounts', 0);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_remove_discount_with_current_assessment_returns_422(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $this->assessStudent($this->student, $this->paymentPlan);
        $snapshot = $this->snapshotAssessmentTables();

        $this->deleteAssignment($studentDiscount->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.assessment.0', StudentDiscountController::CURRENT_ASSESSMENT_MESSAGE);

        $this->assertDatabaseHas('student_discounts', ['id' => $studentDiscount->id]);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_add_discount_with_reassess_reassesses_with_current_plan(): void
    {
        $first = $this->assessStudent($this->student, $this->paymentPlan);

        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->discountType), 'reassess' => true])
            ->assertCreated()
            ->assertJsonPath('student_discount.discount_type_id', $this->discountType->id);

        $this->assertDatabaseCount('student_discounts', 1);
        $current = Assessment::current()->first();
        $this->assertNotSame($first['id'], $current->id);
        $this->assertSame(Assessment::STATUS_SUPERSEDED, Assessment::find($first['id'])->status);
        $this->assertSame($this->paymentPlan->id, $current->payment_plan_id);
        $this->assertSame('4500.00', $current->net_total);
        $this->assertSame(1, $current->discounts()->count());
    }

    public function test_remove_discount_with_reassess_reassesses_with_current_plan(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $first = $this->assessStudent($this->student, $this->paymentPlan);
        $this->assertSame('4500.00', $first['net_total']);

        $this->deleteAssignment($studentDiscount->id, '?reassess=1')->assertNoContent();

        $this->assertDatabaseCount('student_discounts', 0);
        $current = Assessment::current()->first();
        $this->assertNotSame($first['id'], $current->id);
        $this->assertSame('5000.00', $current->net_total);
        $this->assertSame(0, $current->discounts()->count());
        $this->assertSame(2, Assessment::count());
    }

    public function test_reassess_with_inactive_plan_returns_422_and_keeps_discount(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $otherType = $this->createType(['name' => self::VOUCHER_TYPE]);
        $this->assessStudent($this->student, $this->paymentPlan);
        $this->paymentPlan->forceFill(['is_active' => false])->save();
        $snapshot = $this->snapshotAssessmentTables();

        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $otherType), 'reassess' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassess.0', StudentDiscountController::REASSESS_PLAN_INACTIVE_MESSAGE);
        $this->deleteAssignment($studentDiscount->id, '?reassess=1')
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassess.0', StudentDiscountController::REASSESS_PLAN_INACTIVE_MESSAGE);

        $this->assertSame([$studentDiscount->id], StudentDiscount::pluck('id')->all());
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_reassess_failure_rolls_back_discount_change(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $first = $this->assessStudent($this->student, $this->paymentPlan);
        $this->feeSchedule->delete();
        $snapshot = $this->snapshotAssessmentTables();

        $this->deleteAssignment($studentDiscount->id, '?reassess=1')
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassess.0', AssessmentCalculator::NO_FEE_SCHEDULE_MESSAGE);
        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->createType(['name' => self::VOUCHER_TYPE])), 'reassess' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassess.0', AssessmentCalculator::NO_FEE_SCHEDULE_MESSAGE);

        $this->assertSame([$studentDiscount->id], StudentDiscount::pluck('id')->all());
        $this->assertSame(Assessment::STATUS_CURRENT, Assessment::find($first['id'])->status);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_reassess_flag_without_current_assessment_is_ignored(): void
    {
        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->discountType), 'reassess' => true])->assertCreated();
        $this->deleteAssignment(StudentDiscount::first()->id, '?reassess=1')->assertNoContent();

        $this->assertDatabaseCount('student_discounts', 0);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_reassess_flag_must_be_boolean(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);

        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->createType(['name' => self::VOUCHER_TYPE])), 'reassess' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reassess']);
        $this->deleteAssignment($studentDiscount->id, '?reassess=maybe')->assertUnprocessable()->assertJsonValidationErrors(['reassess']);

        $this->assertSame([$studentDiscount->id], StudentDiscount::pluck('id')->all());
    }

    public function test_discount_rules_win_over_assessment_guard(): void
    {
        $this->assignType($this->student, $this->discountType);
        $this->assessStudent($this->student, $this->paymentPlan);

        $this->postAssignment($this->buildAssignmentPayload($this->student, $this->discountType))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_type_id'])
            ->assertJsonPath('errors.discount_type_id.0', StoreStudentDiscountRequest::TYPE_ALREADY_HELD_MESSAGE)
            ->assertJsonMissingValidationErrors(['assessment']);
    }

    public function test_superseded_assessments_do_not_guard(): void
    {
        Assessment::factory()->superseded()->create([
            'student_id' => $this->student->id,
            'school_year_id' => $this->schoolYear->id,
            'payment_plan_id' => $this->paymentPlan->id,
        ]);

        $createdId = $this->postAssignment($this->buildAssignmentPayload($this->student, $this->discountType))->assertCreated()->json('student_discount.id');
        $this->deleteAssignment($createdId)->assertNoContent();

        $this->assertDatabaseCount('student_discounts', 0);
        $this->assertSame(1, Assessment::count());
    }

    public function test_add_discount_blocked_while_payments_exist(): void
    {
        $assessment = $this->assessStudent($this->student, $this->paymentPlan);
        Payment::factory()->create(['assessment_id' => $assessment['id']]);
        $snapshot = $this->snapshotAssessmentTables();

        foreach ([[], ['reassess' => true]] as $extraFields) {
            $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->discountType), ...$extraFields])
                ->assertUnprocessable()
                ->assertJsonPath('errors.reassess.0', Assessment::HAS_PAYMENTS_MESSAGE)
                ->assertJsonMissingValidationErrors(['assessment']);
        }

        $this->assertDatabaseCount('student_discounts', 0);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_remove_discount_blocked_while_payments_exist(): void
    {
        $studentDiscount = $this->assignType($this->student, $this->discountType);
        $assessment = $this->assessStudent($this->student, $this->paymentPlan);
        Payment::factory()->create(['assessment_id' => $assessment['id']]);
        $snapshot = $this->snapshotAssessmentTables();

        foreach (['', '?reassess=1'] as $query) {
            $this->deleteAssignment($studentDiscount->id, $query)
                ->assertUnprocessable()
                ->assertJsonPath('errors.reassess.0', Assessment::HAS_PAYMENTS_MESSAGE)
                ->assertJsonMissingValidationErrors(['assessment']);
        }

        $this->assertDatabaseHas('student_discounts', ['id' => $studentDiscount->id]);
        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_voided_payments_do_not_block_discount_reassess(): void
    {
        $assessment = $this->assessStudent($this->student, $this->paymentPlan);
        Payment::factory()->voided()->create(['assessment_id' => $assessment['id']]);

        $this->postAssignment([...$this->buildAssignmentPayload($this->student, $this->discountType), 'reassess' => true])->assertCreated();

        $this->assertSame(Assessment::STATUS_SUPERSEDED, Assessment::find($assessment['id'])->status);
        $this->assertSame('4500.00', Assessment::current()->first()->net_total);
    }

    private function deleteAssignment(int $studentDiscountId, string $query = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->deleteJson(self::STUDENT_DISCOUNTS_URL.'/'.$studentDiscountId.$query);
    }
}
