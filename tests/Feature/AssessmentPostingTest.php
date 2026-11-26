<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\DiscountType;
use App\Models\FeeItem;
use App\Models\GradeLevel;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentPlan;
use App\Models\Student;
use App\Models\User;
use App\Support\BillingPosting;
use App\Support\JournalNumberAllocator;
use App\Support\UnpostedEventFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithBillingPosting;
use Tests\TestCase;

class AssessmentPostingTest extends TestCase
{
    use InteractsWithBillingPosting;
    use RefreshDatabase;

    private const OVER_LIMIT_AMOUNT = '1000000000.00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
    }

    public function test_tuition_only_assessment_posts_an_entry(): void
    {
        $assessment = $this->assessAndFind($this->createCashPlan());
        $entry = $this->findOriginalEntry($assessment);

        $this->assertSame(['1100' => ['5000.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($entry));
        $this->assertSame([JournalEntry::SOURCE_ASSESSMENT, JournalEntry::SOURCE_ASSESSMENT, $assessment->id], [$entry->source, $entry->source_type, $entry->source_id]);
        $this->assertSame(self::ASSESSED_ON, $entry->entry_date->toDateString());
        $this->assertSame(sprintf('Assessment — %s %s, SY 2030-2031, %s', $assessment->student->first_name, $assessment->student->last_name, $assessment->gradeLevel->name), $entry->description);
        $this->assertSame([JournalEntry::STATUS_POSTED, 'JV-000001', $this->findAdmin()->id], [$entry->status, $entry->number, $entry->posted_by]);
    }

    public function test_school_discount_debits_the_school_discounts_account(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => self::SIBLING_TYPE, 'value' => '500.00']));

        $entry = $this->findOriginalEntry($this->assessAndFind($this->createCashPlan(), $student));

        $this->assertSame(['1100' => ['4500.00', '0.00'], '4090' => ['500.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($entry));
    }

    public function test_government_discount_debits_the_government_receivable(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => self::VOUCHER_TYPE, 'source' => DiscountType::SOURCE_GOVERNMENT, 'value' => '500.00']));

        $entry = $this->findOriginalEntry($this->assessAndFind($this->createCashPlan(), $student));

        $this->assertSame(['1100' => ['4500.00', '0.00'], '1110' => ['500.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($entry));
    }

    public function test_cash_plan_discount_debits_the_cash_payment_discount_account(): void
    {
        $paymentPlan = $this->createCashPlan(['adjustment_kind' => PaymentPlan::KIND_DISCOUNT, 'adjustment_type' => PaymentPlan::TYPE_PERCENT, 'adjustment_value' => '5.00']);

        $entry = $this->findOriginalEntry($this->assessAndFind($paymentPlan));

        $this->assertSame(['1100' => ['4750.00', '0.00'], '4095' => ['250.00', '0.00'], '4010' => ['0.00', '5000.00']], $this->mapLinesByCode($entry));
    }

    public function test_surcharge_plan_credits_the_installment_surcharge_account(): void
    {
        $paymentPlan = $this->createCashPlan(['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '500.00']);

        $entry = $this->findOriginalEntry($this->assessAndFind($paymentPlan));

        $this->assertSame(['1100' => ['5500.00', '0.00'], '4010' => ['0.00', '5000.00'], '4040' => ['0.00', '500.00']], $this->mapLinesByCode($entry));
    }

    public function test_miscellaneous_and_other_fees_credit_their_own_accounts(): void
    {
        $this->createJhsSchedule($this->buildAllCategoryLines());

        $entry = $this->findOriginalEntry($this->assessAndFind($this->createCashPlan(), null, false));

        $this->assertSame(['1100' => ['6200.00', '0.00'], '4010' => ['0.00', '5000.00'], '4020' => ['0.00', '1000.00'], '4030' => ['0.00', '200.00']], $this->mapLinesByCode($entry));
    }

    public function test_all_lines_together_balance_and_zero_lines_are_absent(): void
    {
        $student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->assignType($student, $this->createType(['name' => self::SIBLING_TYPE, 'value' => '500.00']));
        $this->assignType($student, $this->createType(['name' => self::VOUCHER_TYPE, 'source' => DiscountType::SOURCE_GOVERNMENT, 'value' => '300.00']));
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT], [$this->createFeeItem('Lab fee'), '1000.00']]);
        $paymentPlan = $this->createCashPlan(['adjustment_kind' => PaymentPlan::KIND_SURCHARGE, 'adjustment_type' => PaymentPlan::TYPE_FIXED, 'adjustment_value' => '500.00']);

        $entry = $this->findOriginalEntry($this->assessAndFind($paymentPlan, $student, false));

        $this->assertSame(['1100' => ['5700.00', '0.00'], '4090' => ['500.00', '0.00'], '1110' => ['300.00', '0.00'], '4010' => ['0.00', '5000.00'], '4020' => ['0.00', '1000.00'], '4040' => ['0.00', '500.00']], $this->mapLinesByCode($entry));
        $this->assertSame('6500.00', (string) $entry->calculateDebitTotal());
        $this->assertSame('6500.00', (string) $entry->calculateCreditTotal());
    }

    public function test_zero_gross_assessment_posts_nothing_and_is_never_unposted(): void
    {
        $assessment = Assessment::factory()->create();
        $billingPosting = new BillingPosting($this->findAdmin());

        $this->assertFalse($billingPosting->postAssessment($assessment));

        $this->assertNull($billingPosting->lastFailureReason);
        $this->assertSame(0, JournalEntry::count());
        $this->fetchUnposted($this->adminToken)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_create_response_body_keeps_its_keys(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT]]);

        $response = $this->postAssessment($this->buildAssessmentPayload($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan()))->assertCreated();

        $this->assertSame(self::STORED_ASSESSMENT_KEYS, array_keys($response->json('assessment')));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_unmapped_purpose_leaves_the_assessment_unposted(): void
    {
        $this->unmapPurpose('revenue_tuition');

        $assessment = $this->assessAndFind($this->createCashPlan());

        $this->assertNull($this->findOriginalEntry($assessment));
        $this->assertSame(0, JournalEntry::count());
        $this->fetchUnposted($this->adminToken)->assertJsonPath('unposted_events.0.reason', UnpostedEventFinder::REASON_MAPPING_MISSING);
    }

    public function test_inactive_mapped_account_leaves_assessment_unposted(): void
    {
        $this->deactivateSeeded('4010');

        $assessment = $this->assessAndFind($this->createCashPlan());

        $this->assertNull($this->findOriginalEntry($assessment));
        $this->assertSame(0, JournalEntry::count() + JournalLine::count());
        $this->assertDatabaseMissing('receipt_counters', ['series' => JournalNumberAllocator::JV_SERIES]);
        $this->fetchUnposted($this->adminToken)->assertJsonPath('unposted_events.0.reason', UnpostedEventFinder::REASON_ACCOUNT_INACTIVE);
    }

    public function test_unexpected_posting_error_fails_and_saves_nothing(): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT]]);
        $payload = $this->buildAssessmentPayload($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->createCashPlan());
        JournalLine::creating(fn () => throw new RuntimeException('forced failure'));

        $this->postAssessment($payload)->assertStatus(500);

        $this->assertSame(0, Assessment::count() + JournalEntry::count());
        $this->assertDatabaseMissing('receipt_counters', ['series' => JournalNumberAllocator::JV_SERIES]);
    }

    public function test_over_limit_amount_is_left_unposted(): void
    {
        $assessment = $this->createBackfillAssessment(self::OVER_LIMIT_AMOUNT, self::ASSESSED_ON);
        $billingPosting = new BillingPosting($this->findAdmin());

        $this->assertFalse($billingPosting->postAssessment($assessment));

        $this->assertSame(UnpostedEventFinder::REASON_AMOUNT_OVER_LIMIT, $billingPosting->lastFailureReason);
        $this->assertSame(0, JournalEntry::count());
    }

    private function findAdmin(): User
    {
        return User::where('email', 'acting@admin.test')->firstOrFail();
    }

    /** Assesses a Grade 7 student through the endpoint; the schedule is created here unless the caller made one. */
    private function assessAndFind(PaymentPlan $paymentPlan, ?Student $student = null, bool $isScheduleNeeded = true): Assessment
    {
        $student ??= $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        if ($isScheduleNeeded) {
            $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT]]);
        }

        $assessmentId = $this->postAssessment($this->buildAssessmentPayload($student, $paymentPlan))->assertCreated()->json('assessment.id');

        return Assessment::with(['student', 'gradeLevel'])->findOrFail($assessmentId);
    }

    /**
     * @return list<array{0: FeeItem, 1: string}>
     */
    private function buildAllCategoryLines(): array
    {
        return [
            [$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), self::TUITION_AMOUNT],
            [$this->createFeeItem('Lab fee', FeeItem::CATEGORY_MISCELLANEOUS), '1000.00'],
            [$this->createFeeItem('ID card', FeeItem::CATEGORY_OTHER), '200.00'],
        ];
    }
}
