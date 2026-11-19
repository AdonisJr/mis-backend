<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\FeeScheduleLine;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Support\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $assessment;

    private string $cashierToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->assessment = $this->assessForPayments('5000.00', ['2030-08-15', '2030-09-15']);
        $this->cashierToken = $this->createCashierToken();
    }

    public function test_exact_down_payment_marks_first_row_paid(): void
    {
        $this->pay('1000.00');

        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['paid', 'unpaid', 'unpaid'], array_column($ledger['schedule'], 'status'));
        $this->assertSame(['1000.00', '0.00', '0.00'], array_column($ledger['schedule'], 'paid'));
        $this->assertSame('2000.00', $ledger['next_amount_due']);
        $this->assertSame('4000.00', $ledger['balance']);
    }

    public function test_partial_payment_marks_row_partial(): void
    {
        $this->pay('400.00');

        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['partial', 'unpaid', 'unpaid'], array_column($ledger['schedule'], 'status'));
        $this->assertSame('400.00', $ledger['schedule'][0]['paid']);
        $this->assertSame('600.00', $ledger['next_amount_due']);
    }

    public function test_payment_spanning_several_rows_fills_oldest_first(): void
    {
        $this->pay('2500.00');

        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['paid', 'partial', 'unpaid'], array_column($ledger['schedule'], 'status'));
        $this->assertSame(['1000.00', '1500.00', '0.00'], array_column($ledger['schedule'], 'paid'));
        $this->assertSame('500.00', $ledger['next_amount_due']);
    }

    public function test_several_payments_accumulate_oldest_first(): void
    {
        foreach (['1000.00', '1500.00', '600.00'] as $amount) {
            $this->pay($amount);
        }

        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['paid', 'paid', 'partial'], array_column($ledger['schedule'], 'status'));
        $this->assertSame(['1000.00', '2000.00', '100.00'], array_column($ledger['schedule'], 'paid'));
        $this->assertSame('3100.00', $ledger['total_paid']);
        $this->assertSame('1900.00', $ledger['next_amount_due']);
    }

    public function test_last_installment_absorbs_the_cents(): void
    {
        $paymentPlan = $this->createInstallmentPlan(['2030-08-15', '2030-09-15', '2030-10-15'], ['down_payment_value' => '100.00']);
        // the Grade 7 schedule already exists (one per key), so its single tuition line is repriced for the new assessment
        FeeScheduleLine::query()->update(['amount' => '1000.01']);
        $assessment = $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $paymentPlan);

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($assessment['id'], ['amount' => '999.99']))->assertCreated();
        $ledger = $this->fetchLedger($this->cashierToken, $assessment)->assertOk()->json('ledger');

        $this->assertSame(['100.00', '300.00', '300.00', '300.01'], array_column($ledger['schedule'], 'amount'));
        $this->assertSame(['paid', 'paid', 'paid', 'partial'], array_column($ledger['schedule'], 'status'));
        $this->assertSame('299.99', $ledger['schedule'][3]['paid']);
        $this->assertSame('0.02', $ledger['balance']);
        $this->assertSame('0.02', $ledger['next_amount_due']);
    }

    public function test_full_payment_settles_every_row(): void
    {
        $this->pay('5000.00');

        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['paid', 'paid', 'paid'], array_column($ledger['schedule'], 'status'));
        $this->assertSame('0.00', $ledger['balance']);
        $this->assertSame('0.00', $ledger['next_amount_due']);
        $this->assertSame('5000.00', $ledger['total_paid']);
    }

    public function test_overdue_is_strictly_before_today_and_only_when_not_fully_paid(): void
    {
        $this->pay('1000.00');

        $this->travelToManila('2030-08-15 10:00:00');
        $this->assertSame([false, false, false], array_column($this->fetchCurrentLedger()['schedule'], 'is_overdue'));

        $this->travelToManila('2030-08-16 10:00:00');
        $this->assertSame([false, true, false], array_column($this->fetchCurrentLedger()['schedule'], 'is_overdue'));

        $this->pay('2000.00');
        $this->assertSame([false, false, false], array_column($this->fetchCurrentLedger()['schedule'], 'is_overdue'));
    }

    public function test_next_amount_due_is_remainder_of_oldest_unpaid_row(): void
    {
        $this->pay('1000.00');
        $this->pay('500.00');

        $this->assertSame('1500.00', $this->fetchCurrentLedger()['next_amount_due']);
    }

    public function test_void_restores_schedule_and_balance(): void
    {
        $this->pay('1000.00');
        $secondPaymentId = $this->pay('2000.00');

        $this->voidPayment($this->cashierToken, $secondPaymentId)->assertOk();
        $ledger = $this->fetchCurrentLedger();

        $this->assertSame(['paid', 'unpaid', 'unpaid'], array_column($ledger['schedule'], 'status'));
        $this->assertSame('1000.00', $ledger['total_paid']);
        $this->assertSame('4000.00', $ledger['balance']);
        $this->assertSame('2000.00', $ledger['next_amount_due']);
    }

    public function test_balance_after_is_a_snapshot_unchanged_by_later_voids(): void
    {
        $firstPaymentId = $this->pay('1000.00');
        $secondPaymentId = $this->pay('2000.00');

        $this->voidPayment($this->cashierToken, $firstPaymentId)->assertOk();

        $ledger = $this->fetchCurrentLedger();
        $payments = collect($ledger['payments'])->keyBy('id');
        $this->assertSame('4000.00', $payments[$firstPaymentId]['balance_after']);
        $this->assertSame('2000.00', $payments[$secondPaymentId]['balance_after']);
        $this->assertSame('3000.00', $ledger['balance']);
    }

    public function test_statement_detail_reports_total_paid_balance_and_has_payments(): void
    {
        $detailUrl = self::ASSESSMENTS_URL.'/'.$this->assessment['id'];
        $this->sendAs($this->adminToken, 'GET', $detailUrl)
            ->assertOk()
            ->assertJsonPath('assessment.total_paid', '0.00')
            ->assertJsonPath('assessment.balance', '5000.00')
            ->assertJsonPath('assessment.has_payments', false);

        $this->pay('1250.50');

        $detailResponse = $this->sendAs($this->adminToken, 'GET', $detailUrl)
            ->assertOk()
            ->assertJsonPath('assessment.total_paid', '1250.50')
            ->assertJsonPath('assessment.balance', '3749.50')
            ->assertJsonPath('assessment.has_payments', true);
        $this->assertSame(self::STORED_ASSESSMENT_KEYS, array_keys($detailResponse->json('assessment')));
        $this->assertCount(27, $detailResponse->json('assessment'));
    }

    public function test_preview_omits_payment_keys(): void
    {
        $paymentPlan = PaymentPlan::findOrFail($this->assessment['payment_plan_id']);
        $newStudent = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);

        $previewResponse = $this->postPreview($this->buildAssessmentPayload($newStudent, $paymentPlan))->assertOk();

        $this->assertSame(self::ASSESSMENT_KEYS, array_keys($previewResponse->json('assessment')));
        $this->assertCount(24, $previewResponse->json('assessment'));
        $this->assertSame([], array_keys(Arr::only($previewResponse->json('assessment'), self::ASSESSMENT_PAYMENT_KEYS)));
    }

    public function test_superseded_statement_shows_zero_paid(): void
    {
        $paymentId = $this->pay('1000.00');
        $this->voidPayment($this->cashierToken, $paymentId)->assertOk();
        $paymentPlan = PaymentPlan::findOrFail($this->assessment['payment_plan_id']);

        $this->app['auth']->forgetGuards();
        $this->postReassess($this->assessment['id'], $paymentPlan)->assertCreated();

        $this->sendAs($this->adminToken, 'GET', self::ASSESSMENTS_URL.'/'.$this->assessment['id'])
            ->assertOk()
            ->assertJsonPath('assessment.status', Assessment::STATUS_SUPERSEDED)
            ->assertJsonPath('assessment.total_paid', '0.00')
            ->assertJsonPath('assessment.has_payments', false);
        $this->assertTrue(PaymentAllocation::sumValidAmounts(Assessment::find($this->assessment['id'])->payments)->isZero());
    }

    private function pay(string $amount): int
    {
        return $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => $amount]))->assertCreated()->json('payment.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCurrentLedger(): array
    {
        return $this->fetchLedger($this->cashierToken, $this->assessment)->assertOk()->assertJsonCount(count(self::LEDGER_KEYS), 'ledger')->json('ledger');
    }

    private function travelToManila(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, self::MANILA));
    }
}
