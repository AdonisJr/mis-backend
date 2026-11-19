<?php

namespace Tests\Feature\Concerns;

use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithAssessments
{
    use InteractsWithDiscounts;

    private const ASSESSMENTS_URL = '/api/mis/assessments';

    private const ASSESSED_ON = '2030-07-01';

    private const CASH_PLAN = 'Cash';

    private const MONTHLY_PLAN = 'Monthly';

    private const ASSESSMENT_KEYS = [
        'id', 'status', 'student_id', 'school_year_id', 'grade_level_id', 'program_id', 'payment_plan_id', 'student', 'school_year', 'grade_level', 'program', 'plan',
        'fee_lines', 'subtotals', 'gross_total', 'discounts', 'discount_totals', 'discount_total', 'net_total', 'schedule', 'created_by', 'created_at', 'superseded_at', 'superseded_by',
    ];

    private const ASSESSMENT_NULL_KEYS = ['id', 'status', 'created_at', 'superseded_at', 'superseded_by'];

    private const ASSESSMENT_ROW_KEYS = ['id', 'status', 'payment_plan_id', 'plan_name', 'net_total', 'student', 'grade_level', 'program', 'created_by', 'created_at', 'superseded_at', 'superseded_by', 'school_year_id'];

    private const CANDIDATE_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'grade_level', 'program'];

    private function setUpAssessmentFixtures(): void
    {
        $this->setUpFeeFixtures();
        Carbon::setTestNow(self::ASSESSED_ON);
    }

    /**
     * @param  array<string, mixed>  $planFields
     */
    private function createCashPlan(array $planFields = []): PaymentPlan
    {
        return PaymentPlan::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => self::CASH_PLAN, ...$planFields]);
    }

    /**
     * @param  list<string>  $dueDates
     * @param  array<string, mixed>  $planFields
     */
    private function createInstallmentPlan(array $dueDates, array $planFields = []): PaymentPlan
    {
        $paymentPlan = PaymentPlan::factory()->create([
            'school_year_id' => $this->schoolYear->id,
            'name' => self::MONTHLY_PLAN,
            'down_payment_type' => PaymentPlan::TYPE_FIXED,
            'down_payment_value' => '1000.00',
            ...$planFields,
        ]);

        foreach ($dueDates as $dueDateIndex => $dueDate) {
            PaymentPlanInstallment::factory()->create(['payment_plan_id' => $paymentPlan->id, 'sequence' => $dueDateIndex + 1, 'due_date' => $dueDate]);
        }

        return $paymentPlan->load('installments');
    }

    /**
     * @param  list<array{0: FeeItem, 1: string}>  $lineSpecs
     */
    private function createJhsSchedule(array $lineSpecs): FeeSchedule
    {
        return $this->createSchedule($this->buildKeys(self::GRADE_7), $lineSpecs);
    }

    /**
     * @return array<string, int>
     */
    private function buildAssessmentPayload(Student $student, PaymentPlan $paymentPlan): array
    {
        return [
            'student_id' => $student->id,
            'school_year_id' => $this->schoolYear->id,
            'payment_plan_id' => $paymentPlan->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAssessment(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::ASSESSMENTS_URL, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postPreview(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::ASSESSMENTS_URL.'/preview', $payload);
    }

    private function postReassess(int $assessmentId, PaymentPlan $paymentPlan): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::ASSESSMENTS_URL.'/'.$assessmentId.'/reassess', ['payment_plan_id' => $paymentPlan->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assessStudent(Student $student, PaymentPlan $paymentPlan): array
    {
        return $this->postAssessment($this->buildAssessmentPayload($student, $paymentPlan))->assertCreated()->json('assessment');
    }

    /**
     * A student in Grade 7 with a schedule of one tuition line and the given plan, assessed through the endpoint.
     *
     * @return array<string, mixed>
     */
    private function assessWithTuition(string $tuitionAmount, PaymentPlan $paymentPlan, ?Student $student = null): array
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), $tuitionAmount]]);

        return $this->assessStudent($student ?? $this->createEnrolledStudent(GradeLevel::STAGE_JHS), $paymentPlan);
    }

    /**
     * @return list<array<int, object>>
     */
    private function snapshotAssessmentTables(): array
    {
        return array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->all(),
            ['assessments', 'assessment_fee_lines', 'assessment_discounts', 'assessment_installments'],
        );
    }
}
