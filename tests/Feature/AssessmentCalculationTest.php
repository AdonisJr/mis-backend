<?php

namespace Tests\Feature;

use App\Models\DiscountType;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\FeeScheduleLine;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAssessments;
use Tests\TestCase;

class AssessmentCalculationTest extends TestCase
{
    use InteractsWithAssessments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_gross_totals_per_category_include_inactive_fee_items(): void
    {
        $inactiveItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);
        $inactiveItem->forceFill(['is_active' => false])->save();
        $this->createJhsSchedule([
            [$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '10000.00'],
            [$this->createFeeItem(self::LIBRARY_FEE), '500.00'],
            [$inactiveItem, '150.00'],
        ]);

        $assessment = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());

        $this->assertSame(['tuition' => '10000.00', 'miscellaneous' => '500.00', 'other' => '150.00'], $assessment['subtotals']);
        $this->assertSame('10650.00', $assessment['gross_total']);
        $this->assertSame('0.00', $assessment['discount_total']);
        $this->assertSame('10650.00', $assessment['net_total']);
        $this->assertSame(
            [['name' => self::TUITION_FEE, 'category' => 'tuition', 'amount' => '10000.00'], ['name' => self::LIBRARY_FEE, 'category' => 'miscellaneous', 'amount' => '500.00'], ['name' => self::ID_CARD, 'category' => 'other', 'amount' => '150.00']],
            $assessment['fee_lines'],
        );
    }

    public function test_percent_discount_is_computed_on_gross_base_and_rounded_half_up(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1234.45']]);
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'value_type' => DiscountType::VALUE_TYPE_PERCENT, 'value' => '10.00']);
        $firstStudent = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($firstStudent, $discountType);

        $firstAssessment = $this->assessStudent($firstStudent, $this->createCashPlan());

        $this->assertSame('123.45', $firstAssessment['discounts'][0]['computed_amount']);
        $this->assertSame('123.45', $firstAssessment['discounts'][0]['applied_amount']);

        FeeScheduleLine::query()->update(['amount' => '0.05']);
        $secondStudent = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($secondStudent, $discountType);

        $secondAssessment = $this->assessStudent($secondStudent, PaymentPlan::first());

        $this->assertSame('0.01', $secondAssessment['discounts'][0]['computed_amount']);
        $this->assertSame('0.04', $secondAssessment['net_total']);
    }

    public function test_tuition_based_discounts_are_capped_at_tuition(): void
    {
        $this->createJhsSchedule([
            [$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00'],
            [$this->createFeeItem(self::LIBRARY_FEE), '300.00'],
        ]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'A grant', 'value' => '800.00']));
        $this->assignType($student, $this->createType(['name' => 'B grant', 'value' => '700.00']));

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame('1000.00', $assessment['discount_totals']['tuition']);
        $this->assertSame('0.00', $assessment['discount_totals']['miscellaneous']);
        $this->assertSame('1000.00', $assessment['discount_total']);
        $this->assertSame('300.00', $assessment['net_total']);
    }

    public function test_total_based_discounts_fill_tuition_then_miscellaneous_then_other(): void
    {
        $this->createJhsSchedule([
            [$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00'],
            [$this->createFeeItem(self::LIBRARY_FEE), '500.00'],
            [$this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER), '200.00'],
        ]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Total grant', 'value' => '1300.00', 'base' => DiscountType::BASE_TOTAL]));

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame(['tuition' => '1000.00', 'miscellaneous' => '300.00', 'other' => '0.00'], $assessment['discount_totals']);
        $this->assertSame('1300.00', $assessment['discount_total']);
        $this->assertSame('400.00', $assessment['net_total']);
    }

    public function test_cap_trims_government_before_school_then_by_name(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Bravo grant', 'value' => '600.00']));
        $this->assignType($student, $this->createType(['name' => 'Alpha grant', 'value' => '600.00']));
        $this->assignType($student, DiscountType::factory()->bothStages()->government()->create(['name' => 'Zed grant', 'value' => '600.00']));

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame(['Zed grant', 'Alpha grant', 'Bravo grant'], array_column($assessment['discounts'], 'name'));
        $this->assertSame(['600.00', '600.00', '600.00'], array_column($assessment['discounts'], 'computed_amount'));
        $this->assertSame(['0.00', '400.00', '600.00'], array_column($assessment['discounts'], 'applied_amount'));
        $this->assertSame('1000.00', $assessment['discount_total']);
        $this->assertSame('0.00', $assessment['net_total']);
    }

    public function test_discounts_of_inactive_types_still_apply(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, DiscountType::factory()->bothStages()->inactive()->create(['name' => 'Retired grant', 'value' => '100.00']));

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame('100.00', $assessment['discounts'][0]['applied_amount']);
        $this->assertSame('900.00', $assessment['net_total']);
    }

    public function test_cash_plan_percent_discount_is_taken_on_tuition_after_discounts(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '10000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Flat grant', 'value' => '1000.00']));
        $paymentPlan = $this->createCashPlan(['adjustment_kind' => PaymentPlan::KIND_DISCOUNT, 'adjustment_type' => PaymentPlan::TYPE_PERCENT, 'adjustment_value' => '10.00']);

        $assessment = $this->assessStudent($student, $paymentPlan);

        $this->assertSame(['name' => self::CASH_PLAN, 'adjustment' => ['kind' => 'discount', 'type' => 'percent', 'value' => '10.00', 'amount' => '900.00']], $assessment['plan']);
        $this->assertSame('8100.00', $assessment['net_total']);
    }

    public function test_cash_plan_discount_is_capped_so_tuition_stays_at_zero(): void
    {
        $this->createJhsSchedule([
            [$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00'],
            [$this->createFeeItem(self::LIBRARY_FEE), '300.00'],
        ]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Full tuition grant', 'value' => '1000.00']));
        $paymentPlan = $this->createCashPlan(['adjustment_kind' => PaymentPlan::KIND_DISCOUNT, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '500.00']);

        $assessment = $this->assessStudent($student, $paymentPlan);

        $this->assertSame('0.00', $assessment['plan']['adjustment']['amount']);
        $this->assertSame('500.00', $assessment['plan']['adjustment']['value']);
        $this->assertSame('300.00', $assessment['net_total']);
    }

    public function test_installment_plan_fixed_surcharge_is_added_to_tuition(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15'], ['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '500.00']);

        $assessment = $this->assessWithTuition('10000.00', $paymentPlan);

        $this->assertSame('500.00', $assessment['plan']['adjustment']['amount']);
        $this->assertSame('10500.00', $assessment['net_total']);
        $this->assertSame(['1000.00', '4750.00', '4750.00'], array_column($assessment['schedule'], 'amount'));
    }

    public function test_installment_plan_percent_surcharge_is_rounded_half_up(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15'], ['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_PERCENT, 'adjustment_value' => '1.00']);

        $assessment = $this->assessWithTuition('1234.50', $paymentPlan);

        $this->assertSame('12.35', $assessment['plan']['adjustment']['amount']);
        $this->assertSame('1246.85', $assessment['net_total']);
    }

    public function test_cash_plan_has_one_full_payment_due_on_assessment(): void
    {
        $assessment = $this->assessWithTuition('5000.00', $this->createCashPlan());

        $this->assertSame([['sequence' => 1, 'label' => 'Full payment', 'due_date' => self::ASSESSED_ON, 'amount' => '5000.00']], $assessment['schedule']);
    }

    public function test_cash_plan_with_zero_net_total_has_one_zero_payment(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => 'Full tuition grant', 'value' => '1000.00']));

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame('0.00', $assessment['net_total']);
        $this->assertSame([['sequence' => 1, 'label' => 'Full payment', 'due_date' => self::ASSESSED_ON, 'amount' => '0.00']], $assessment['schedule']);
    }

    public function test_fixed_down_payment_then_equal_installments_on_plan_due_dates(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15']);

        $assessment = $this->assessWithTuition('10000.00', $paymentPlan);

        $this->assertSame([
            ['sequence' => 1, 'label' => 'Down payment', 'due_date' => self::ASSESSED_ON, 'amount' => '1000.00'],
            ['sequence' => 2, 'label' => 'Installment 1', 'due_date' => '2030-08-15', 'amount' => '4500.00'],
            ['sequence' => 3, 'label' => 'Installment 2', 'due_date' => '2030-09-15', 'amount' => '4500.00'],
        ], $assessment['schedule']);
    }

    public function test_percent_down_payment_is_taken_on_net_total_including_surcharge(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15'], [
            'down_payment_type' => PaymentPlan::TYPE_PERCENT,
            'down_payment_value' => '20.00',
            'adjustment_kind' => PaymentPlan::KIND_SURCHARGE,
            'adjustment_type' => PaymentPlan::TYPE_FIXED,
            'adjustment_value' => '500.00',
        ]);

        $assessment = $this->assessWithTuition('10000.00', $paymentPlan);

        $this->assertSame('10500.00', $assessment['net_total']);
        $this->assertSame(['2100.00', '4200.00', '4200.00'], array_column($assessment['schedule'], 'amount'));
    }

    public function test_last_installment_absorbs_the_cents(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15', '2030-10-15'], ['down_payment_value' => '0.00']);

        $assessment = $this->assessWithTuition('100.00', $paymentPlan);

        $this->assertCount(4, $assessment['schedule']);
        $this->assertSame(['0.00', '33.33', '33.33', '33.34'], array_column($assessment['schedule'], 'amount'));
        $this->assertSame(['Down payment', 'Installment 1', 'Installment 2', 'Installment 3'], array_column($assessment['schedule'], 'label'));
    }

    public function test_fixed_down_payment_is_capped_at_net_total_and_installments_are_zero(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15']);

        $assessment = $this->assessWithTuition('500.00', $paymentPlan);

        $this->assertSame(['500.00', '0.00', '0.00'], array_column($assessment['schedule'], 'amount'));
    }

    public function test_tiny_balance_falls_back_to_rounding_down(): void
    {
        $dueDates = [
            ...array_map(fn (int $month) => sprintf('2030-%02d-15', $month), range(7, 12)),
            ...array_map(fn (int $month) => sprintf('2031-%02d-15', $month), range(1, 6)),
        ];
        $paymentPlan = $this->createInstallmentPlan($dueDates, ['down_payment_value' => '0.00']);

        $assessment = $this->assessWithTuition('0.10', $paymentPlan);

        $amounts = array_column($assessment['schedule'], 'amount');
        $this->assertCount(13, $amounts);
        $this->assertSame(['0.00', ...array_fill(0, 11, '0.00'), '0.10'], $amounts);
    }

    public function test_program_schedule_wins_over_grade_level_default(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_SHS);
        $programId = Enrollment::where('student_id', $student->id)->first()->section->program_id;
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $this->createSchedule($this->buildKeys(self::GRADE_11), [[$tuitionItem, '1000.00']]);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $programId), [[$tuitionItem, '2000.00']]);

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame('2000.00', $assessment['gross_total']);
        $this->assertSame($programId, $assessment['program_id']);
        $this->assertSame($programId, $assessment['program']['id']);
    }

    public function test_grade_level_default_schedule_is_used_without_program_schedule(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_SHS);
        $this->createSchedule($this->buildKeys(self::GRADE_11), [[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1000.00']]);

        $assessment = $this->assessStudent($student, $this->createCashPlan());

        $this->assertSame('1000.00', $assessment['gross_total']);
        $this->assertSame(self::GRADE_11, $assessment['grade_level']['name']);
    }
}
