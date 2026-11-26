<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PostingMapping;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithReports;
use Tests\TestCase;

class ArAgingTest extends TestCase
{
    use InteractsWithReports;
    use RefreshDatabase;

    private const AR_AGING = 'ar-aging';

    private const INSTALLMENT = '100.00';

    private const REPORT_KEYS = ['as_of', 'rows', 'totals', 'tie_out'];

    private const ROW_KEYS = ['assessment_id', 'student', 'school_year', 'grade_level', 'current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total'];

    private const STUDENT_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name'];

    private const TOTALS_KEYS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total'];

    private const TIE_OUT_KEYS = ['aging_total', 'gl_balance', 'difference', 'is_mapped'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideDaysPastDue(): array
    {
        return [
            'due on the as-of date' => [0, 'current'],
            '1 day' => [1, 'days_1_30'],
            '30 days' => [30, 'days_1_30'],
            '31 days' => [31, 'days_31_60'],
            '60 days' => [60, 'days_31_60'],
            '61 days' => [61, 'days_61_90'],
            '90 days' => [90, 'days_61_90'],
            '91 days' => [91, 'over_90'],
        ];
    }

    #[DataProvider('provideDaysPastDue')]
    public function test_installment_lands_in_the_bucket_of_its_days_past_due(int $daysPastDue, string $expectedBucket): void
    {
        $dueDate = Carbon::parse(self::AS_OF)->subDays($daysPastDue)->toDateString();
        $this->createAgingAssessment([[$dueDate, self::INSTALLMENT]]);

        $agingRow = $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)->assertOk()->json('ar_aging.rows.0');

        foreach (self::AGING_BUCKET_KEYS as $bucket) {
            $this->assertSame($bucket === $expectedBucket ? self::INSTALLMENT : self::ZERO, $agingRow[$bucket], $bucket);
        }
        $this->assertSame(self::INSTALLMENT, $agingRow['total']);
    }

    public function test_future_due_date_is_current(): void
    {
        $this->createAgingAssessment([['2030-07-15', self::INSTALLMENT]]);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.rows.0.current', self::INSTALLMENT);
    }

    public function test_payment_fills_the_oldest_due_date_first(): void
    {
        $assessment = $this->createAgingAssessment([['2030-05-01', self::INSTALLMENT], ['2030-06-20', self::INSTALLMENT]]);
        Payment::factory()->create(['assessment_id' => $assessment->id, 'amount' => self::INSTALLMENT, 'payment_date' => '2030-06-25']);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.rows.0.days_61_90', self::ZERO)
            ->assertJsonPath('ar_aging.rows.0.days_1_30', self::INSTALLMENT)
            ->assertJsonPath('ar_aging.rows.0.total', self::INSTALLMENT);
    }

    public function test_scenario_rows_are_ordered_by_name_with_exact_keys_and_footer_totals(): void
    {
        $this->seedBillingLedger();

        $agingReport = $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)->assertOk()->json('ar_aging');

        $this->assertSame(self::REPORT_KEYS, array_keys($agingReport));
        $this->assertSame(self::ROW_KEYS, array_keys($agingReport['rows'][0]));
        $this->assertSame(self::STUDENT_KEYS, array_keys($agingReport['rows'][0]['student']));
        $this->assertSame(['id', 'name'], array_keys($agingReport['rows'][0]['school_year']));
        $this->assertSame(['id', 'name'], array_keys($agingReport['rows'][0]['grade_level']));
        $this->assertSame(['Abad', 'Bautista'], array_column(array_column($agingReport['rows'], 'student'), 'last_name'));
        $this->assertSame(['4000.00', '5000.00'], array_column($agingReport['rows'], 'total'));
        $this->assertSame(['4000.00', '5000.00'], array_column($agingReport['rows'], 'current'));
        $this->assertSame(self::TOTALS_KEYS, array_keys($agingReport['totals']));
        $this->assertSame('9000.00', $agingReport['totals']['current']);
        $this->assertSame('9000.00', $agingReport['totals']['total']);
        $this->assertSame(self::TIE_OUT_KEYS, array_keys($agingReport['tie_out']));
        $this->assertSame(['9000.00', '9000.00', self::ZERO, true], array_values($agingReport['tie_out']));
    }

    public function test_fully_paid_assessment_is_omitted(): void
    {
        $assessment = $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]]);
        Payment::factory()->create(['assessment_id' => $assessment->id, 'amount' => self::INSTALLMENT, 'payment_date' => '2030-06-10']);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.rows', [])
            ->assertJsonPath('ar_aging.totals.total', self::ZERO);
    }

    public function test_payment_dated_after_as_of_is_ignored(): void
    {
        $assessment = $this->createAgingAssessment([['2030-05-01', self::INSTALLMENT]]);
        Payment::factory()->create(['assessment_id' => $assessment->id, 'amount' => self::INSTALLMENT, 'payment_date' => '2030-06-20']);

        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-19')->assertOk()->assertJsonPath('ar_aging.rows.0.total', self::INSTALLMENT);
        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-20')->assertOk()->assertJsonPath('ar_aging.rows', []);
    }

    public function test_void_after_as_of_still_counts_and_void_on_as_of_does_not(): void
    {
        $assessment = $this->createAgingAssessment([['2030-05-01', self::INSTALLMENT]]);
        Payment::factory()->voided()->create(['assessment_id' => $assessment->id, 'amount' => self::INSTALLMENT, 'payment_date' => '2030-06-10', 'voided_at' => '2030-06-20 10:00:00']);

        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-19')->assertOk()->assertJsonPath('ar_aging.rows', []);
        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-20')->assertOk()->assertJsonPath('ar_aging.rows.0.total', self::INSTALLMENT);
    }

    public function test_reassessment_replaces_the_old_assessment_from_its_own_day(): void
    {
        $assessmentId = $this->assessAndReassessWithDiscount('2030-06-01', '2030-06-25');

        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-24')
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.assessment_id', $assessmentId)
            ->assertJsonPath('ar_aging.rows.0.total', '5000.00');
        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-25')
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.total', '4500.00');
    }

    public function test_same_day_reassessment_lists_only_the_replacement(): void
    {
        $this->assessAndReassessWithDiscount('2030-06-25', '2030-06-25');

        $this->fetchReport(self::AR_AGING, 'as_of=2030-06-25')
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.total', '4500.00');
    }

    public function test_school_year_filter_keeps_one_year(): void
    {
        $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]]);
        $otherYearAssessment = $this->createAgingAssessment([['2030-06-01', '200.00']]);
        $otherYearAssessment->forceFill(['school_year_id' => SchoolYear::factory()->create()->id])->save();

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF.'&school_year_id='.$otherYearAssessment->school_year_id)
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.assessment_id', $otherYearAssessment->id)
            ->assertJsonPath('ar_aging.totals.total', '200.00');
    }

    public function test_grade_level_filter_keeps_one_grade(): void
    {
        $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]]);
        $gradeEightAssessment = $this->createAgingAssessment([['2030-06-01', '200.00']]);
        $gradeEightAssessment->forceFill(['grade_level_id' => $this->findGradeLevelId('Grade 8')])->save();

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF.'&grade_level_id='.$gradeEightAssessment->grade_level_id)
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.assessment_id', $gradeEightAssessment->id);
    }

    public function test_search_requires_every_word_to_match(): void
    {
        foreach ([['Mariel', 'Quintos'], ['Ana', 'Abad'], ['Ben', 'Bautista']] as [$firstName, $lastName]) {
            $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]])->student->update(['first_name' => $firstName, 'last_name' => $lastName]);
        }

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF.'&search='.urlencode('mar quin'))
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.rows.0.student.last_name', 'Quintos');
    }

    public function test_unposted_assessment_shows_as_the_tie_out_difference(): void
    {
        $this->createAgingAssessment([['2030-06-01', '700.00']]);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.tie_out', ['aging_total' => '700.00', 'gl_balance' => self::ZERO, 'difference' => '700.00', 'is_mapped' => true]);
    }

    public function test_unmapped_receivable_account_leaves_the_tie_out_empty(): void
    {
        $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]]);
        $this->unmapPurpose(PostingMapping::PURPOSE_AR_STUDENTS);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.tie_out', ['aging_total' => self::INSTALLMENT, 'gl_balance' => null, 'difference' => null, 'is_mapped' => false]);
    }

    public function test_filtered_totals_differ_from_the_unfiltered_tie_out_total(): void
    {
        $this->seedBillingLedger();

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF.'&search=abad')
            ->assertOk()
            ->assertJsonCount(1, 'ar_aging.rows')
            ->assertJsonPath('ar_aging.totals.total', '4000.00')
            ->assertJsonPath('ar_aging.tie_out.aging_total', '9000.00')
            ->assertJsonPath('ar_aging.tie_out.difference', self::ZERO);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->fetchReport(self::AR_AGING, 'school_year_id=999999&grade_level_id=abc&search='.str_repeat('a', 256))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id', 'grade_level_id', 'search']);
    }

    public function test_payments_beyond_the_schedule_show_as_a_negative_current_balance(): void
    {
        $assessment = $this->createAgingAssessment([['2030-06-01', self::INSTALLMENT]]);
        Payment::factory()->create(['assessment_id' => $assessment->id, 'amount' => '150.00', 'payment_date' => '2030-06-15']);

        $this->fetchReport(self::AR_AGING, 'as_of='.self::AS_OF)
            ->assertOk()
            ->assertJsonPath('ar_aging.rows.0.current', '-50.00')
            ->assertJsonPath('ar_aging.rows.0.total', '-50.00');
    }

    private function assessAndReassessWithDiscount(string $assessedOn, string $reassessedOn): int
    {
        $this->travelTo($assessedOn);
        $assessment = $this->assessWithTuition(self::TUITION_AMOUNT, $this->createCashPlan());
        $this->travelTo($reassessedOn);
        $discountPlan = $this->createCashPlan([
            'name' => 'Cash 500 off',
            'adjustment_kind' => PaymentPlan::KIND_DISCOUNT,
            'adjustment_type' => PaymentPlan::TYPE_FIXED,
            'adjustment_value' => '500.00',
        ]);
        $this->postReassess($assessment['id'], $discountPlan)->assertSuccessful();
        $this->travelTo(self::AS_OF);

        return $assessment['id'];
    }
}
