<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentPlanCopyController;
use App\Http\Requests\CopyFeeSchedulesRequest;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithPaymentPlans;
use Tests\TestCase;

class PaymentPlanCopyTest extends TestCase
{
    use InteractsWithPaymentPlans;
    use RefreshDatabase;

    private const COPY_URL = self::PAYMENT_PLANS_URL.'/copy';

    private SchoolYear $targetYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
    }

    public function test_copy_copies_every_plan_with_shifted_installments(): void
    {
        $this->createMonthlyPlan(['description' => 'Two parts']);
        $this->createPlan(['name' => self::QUARTERLY_PLAN, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2030-09-01', '2030-12-01', '2031-03-01']);

        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 2, 'skipped' => []]);

        $copiedPlans = PaymentPlan::where('school_year_id', $this->targetYear->id)->orderBy('name')->get();
        $this->assertSame([self::MONTHLY_PLAN, self::QUARTERLY_PLAN], $copiedPlans->pluck('name')->all());
        $this->assertSame('Two parts', $copiedPlans[0]->description);
        $this->assertSame(['2031-07-15', '2031-08-15'], $this->listDueDates($copiedPlans[0]));
        $this->assertSame([1, 2], $copiedPlans[0]->installments->pluck('sequence')->all());
        $this->assertSame(['2031-09-01', '2031-12-01', '2032-03-01'], $this->listDueDates($copiedPlans[1]));
        $this->assertSame(2, PaymentPlan::where('school_year_id', $this->schoolYear->id)->count());
        $this->assertSame(['2030-07-15', '2030-08-15'], $this->listDueDates(PaymentPlan::where('name', self::MONTHLY_PLAN)->where('school_year_id', $this->schoolYear->id)->first()));
    }

    public function test_copy_turns_feb_29_into_feb_28(): void
    {
        $sourceYear = $this->targetYear;
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2032);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'school_year_id' => $sourceYear->id, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2032-02-29']);

        $this->postCopy($sourceYear, $targetYear)->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertSame(['2033-02-28'], $this->listDueDates(PaymentPlan::where('school_year_id', $targetYear->id)->first()));
    }

    public function test_copy_keeps_feb_29_when_target_year_has_it(): void
    {
        $sourceYear = $this->targetYear;
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2035);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'school_year_id' => $sourceYear->id, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2032-02-29']);

        $this->postCopy($sourceYear, $targetYear)->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertSame(['2036-02-29'], $this->listDueDates(PaymentPlan::where('school_year_id', $targetYear->id)->first()));
    }

    public function test_copy_keeps_inactive_plans_inactive(): void
    {
        $this->createMonthlyPlan(['is_active' => false]);

        $this->postCopy()->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertFalse(PaymentPlan::where('school_year_id', $this->targetYear->id)->first()->is_active);
    }

    public function test_copy_keeps_down_payment_and_adjustment(): void
    {
        $this->createMonthlyPlan(['down_payment_type' => 'percent', 'down_payment_value' => '20.00', 'adjustment_kind' => 'surcharge', 'adjustment_type' => 'fixed', 'adjustment_value' => '500.00']);

        $this->postCopy()->assertOk();

        $copiedPlan = PaymentPlan::where('school_year_id', $this->targetYear->id)->first();
        $this->assertSame(['percent', '20.00', 'surcharge', 'fixed', '500.00'], [$copiedPlan->down_payment_type, $copiedPlan->down_payment_value, $copiedPlan->adjustment_kind, $copiedPlan->adjustment_type, $copiedPlan->adjustment_value]);
    }

    public function test_copy_copies_cash_plan(): void
    {
        $this->createPlan(['name' => self::CASH_PLAN, 'adjustment_kind' => 'discount', 'adjustment_type' => 'percent', 'adjustment_value' => '5.00']);

        $this->postCopy()->assertOk()->assertJsonPath('copied_count', 1);

        $copiedPlan = PaymentPlan::where('school_year_id', $this->targetYear->id)->first();
        $this->assertTrue($copiedPlan->isCash());
        $this->assertSame('discount', $copiedPlan->adjustment_kind);
        $this->assertNull($copiedPlan->down_payment_type);
    }

    public function test_copy_skips_plan_whose_name_exists_in_target_ignoring_case(): void
    {
        $this->createMonthlyPlan();
        $this->createPlan(['name' => strtoupper(self::MONTHLY_PLAN), 'school_year_id' => $this->targetYear->id]);

        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 0, 'skipped' => [['name' => self::MONTHLY_PLAN, 'reason' => PaymentPlanCopyController::SKIP_NAME_EXISTS_REASON]]]);

        $this->assertSame(1, PaymentPlan::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_copy_skips_plan_with_shifted_date_outside_target(): void
    {
        $shortYear = SchoolYear::factory()->create(['name' => 'Short', 'start_date' => '2031-06-01', 'end_date' => '2031-12-31']);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2030-07-15', '2031-03-31']);
        $this->createPlan(['name' => self::CASH_PLAN]);

        $this->postCopy($this->schoolYear, $shortYear)
            ->assertOk()
            ->assertExactJson(['copied_count' => 1, 'skipped' => [['name' => self::MONTHLY_PLAN, 'reason' => PaymentPlanCopyController::SKIP_DATE_OUTSIDE_REASON]]]);

        $this->assertSame([self::CASH_PLAN], PaymentPlan::where('school_year_id', $shortYear->id)->pluck('name')->all());
        $this->assertDatabaseCount('payment_plan_installments', 2);
    }

    public function test_copy_reports_name_reason_before_date_reason(): void
    {
        $shortYear = SchoolYear::factory()->create(['name' => 'Short', 'start_date' => '2031-06-01', 'end_date' => '2031-12-31']);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2031-03-31']);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'school_year_id' => $shortYear->id]);

        $this->postCopy($this->schoolYear, $shortYear)
            ->assertOk()
            ->assertJsonPath('skipped.0.reason', PaymentPlanCopyController::SKIP_NAME_EXISTS_REASON);
    }

    public function test_copy_skipped_rows_have_name_and_reason_in_name_order(): void
    {
        $this->createPlan(['name' => self::QUARTERLY_PLAN]);
        $this->createPlan(['name' => self::CASH_PLAN]);
        $this->createPlan(['name' => self::MONTHLY_PLAN]);
        foreach ([self::QUARTERLY_PLAN, self::CASH_PLAN] as $takenName) {
            $this->createPlan(['name' => $takenName, 'school_year_id' => $this->targetYear->id]);
        }

        $copyResponse = $this->postCopy()->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertSame([
            ['name' => self::CASH_PLAN, 'reason' => PaymentPlanCopyController::SKIP_NAME_EXISTS_REASON],
            ['name' => self::QUARTERLY_PLAN, 'reason' => PaymentPlanCopyController::SKIP_NAME_EXISTS_REASON],
        ], $copyResponse->json('skipped'));
    }

    public function test_copy_into_same_year_is_refused(): void
    {
        $this->createMonthlyPlan();
        $snapshot = $this->snapshotPlanTables();

        foreach ([[$this->schoolYear->id, $this->schoolYear->id], [$this->schoolYear->id, (string) $this->schoolYear->id]] as [$sourceId, $targetId]) {
            $this->withToken($this->adminToken)
                ->postJson(self::COPY_URL, ['source_school_year_id' => $sourceId, 'target_school_year_id' => $targetId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['target_school_year_id' => CopyFeeSchedulesRequest::SAME_YEAR_MESSAGE]);
        }

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_copy_rejects_invalid_body(): void
    {
        $this->withToken($this->adminToken)->postJson(self::COPY_URL, [])->assertUnprocessable()->assertJsonValidationErrors(['source_school_year_id', 'target_school_year_id']);
        $this->withToken($this->adminToken)
            ->postJson(self::COPY_URL, ['source_school_year_id' => self::MISSING_ID, 'target_school_year_id' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_school_year_id', 'target_school_year_id']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_copy_of_empty_source_returns_zero(): void
    {
        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 0, 'skipped' => []]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_copy_rolls_back_when_a_write_fails(): void
    {
        $this->createPlan(['name' => self::CASH_PLAN]);
        $this->createPlan(['name' => self::MONTHLY_PLAN]);
        $createdCount = 0;
        PaymentPlan::created(function () use (&$createdCount) {
            $createdCount++;

            if ($createdCount === 2) {
                throw new RuntimeException('Simulated write failure');
            }
        });

        try {
            $this->postCopy()->assertStatus(500);
        } finally {
            PaymentPlan::flushEventListeners();
        }

        $this->assertSame(0, PaymentPlan::where('school_year_id', $this->targetYear->id)->count());
        $this->assertSame(2, PaymentPlan::where('school_year_id', $this->schoolYear->id)->count());
        $this->assertSame(0, PaymentPlanInstallment::count());
    }

    private function postCopy(?SchoolYear $sourceYear = null, ?SchoolYear $targetYear = null): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::COPY_URL, [
            'source_school_year_id' => ($sourceYear ?? $this->schoolYear)->id,
            'target_school_year_id' => ($targetYear ?? $this->targetYear)->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listDueDates(PaymentPlan $paymentPlan): array
    {
        return $paymentPlan->installments()->get()->map(fn (PaymentPlanInstallment $installment) => $installment->due_date->toDateString())->all();
    }
}
