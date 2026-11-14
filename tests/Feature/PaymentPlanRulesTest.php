<?php

namespace Tests\Feature;

use App\Http\Requests\StorePaymentPlanRequest;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentPlans;
use Tests\TestCase;

class PaymentPlanRulesTest extends TestCase
{
    use InteractsWithPaymentPlans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideMalformedDueDates(): array
    {
        return [
            'slashes' => ['2030/07/01'],
            'impossible date' => ['2030-02-30'],
            'integer' => [123],
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function provideInvalidDownPayments(): array
    {
        return [
            'fixed zero' => ['fixed', 0],
            'fixed negative' => ['fixed', -1],
            'fixed three decimals' => ['fixed', 1.234],
            'fixed exponent string' => ['fixed', '1e3'],
            'fixed boolean' => ['fixed', true],
            'fixed overflow' => ['fixed', 10000000000],
            'percent zero' => ['percent', 0],
            'percent hundred' => ['percent', 100],
            'percent above hundred' => ['percent', 100.01],
            'percent three decimals' => ['percent', 12.345],
        ];
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function provideInvalidAdjustments(): array
    {
        return [
            'fixed zero' => ['fixed', 0],
            'fixed three decimals' => ['fixed', 1.234],
            'fixed overflow' => ['fixed', 10000000000],
            'percent zero' => ['percent', 0],
            'percent above hundred' => ['percent', 100.01],
            'percent three decimals' => ['percent', 1.234],
        ];
    }

    public function test_create_requires_school_year_name_and_due_dates(): void
    {
        $this->postPlan([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id', 'name', 'due_dates']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_name_is_unique_per_school_year_ignoring_case(): void
    {
        $this->createMonthlyPlan();
        $snapshot = $this->snapshotPlanTables();

        foreach ([strtoupper(self::MONTHLY_PLAN), ' monthly ', self::MONTHLY_PLAN] as $takenName) {
            $this->postPlan($this->buildPlanPayload(['name' => $takenName]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name' => StorePaymentPlanRequest::NAME_TAKEN_MESSAGE]);
        }

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_update_refuses_name_taken_by_another_plan_ignoring_case(): void
    {
        $this->createMonthlyPlan();
        $quarterlyPlan = $this->createPlan(['name' => self::QUARTERLY_PLAN, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2030-09-01']);
        $snapshot = $this->snapshotPlanTables();

        $this->putPlan($quarterlyPlan->id, $this->buildPlanPayload(['name' => strtolower(self::MONTHLY_PLAN)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StorePaymentPlanRequest::NAME_TAKEN_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_same_name_in_another_school_year_is_allowed(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createMonthlyPlan();

        $this->postPlan($this->buildPlanPayload(['school_year_id' => $otherYear->id, 'due_dates' => ['2031-07-15', '2031-08-15']]))->assertCreated();

        $this->assertDatabaseCount('payment_plans', 2);
    }

    public function test_oversized_name_and_description_are_refused(): void
    {
        $this->postPlan($this->buildPlanPayload(['name' => str_repeat('n', 101), 'description' => str_repeat('d', 1001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description']);

        $this->assertDatabaseCount('payment_plans', 0);

        $this->postPlan($this->buildPlanPayload(['name' => str_repeat('n', 100), 'description' => str_repeat('d', 1000)]))->assertCreated();
    }

    public function test_due_dates_must_be_a_list_of_at_most_twelve(): void
    {
        $thirteenDates = array_map(fn (int $month) => sprintf('2030-%02d-15', $month), range(6, 12));
        $thirteenDates = [...$thirteenDates, '2031-01-15', '2031-02-15', '2031-03-15', '2031-03-16', '2031-03-17', '2031-03-18'];

        $this->postPlan($this->buildPlanPayload(['due_dates' => $thirteenDates]))->assertUnprocessable()->assertJsonValidationErrors(['due_dates']);
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['a' => '2030-07-15']]))->assertUnprocessable()->assertJsonValidationErrors(['due_dates']);
        $this->postPlan($this->buildPlanPayload(['due_dates' => null]))->assertUnprocessable()->assertJsonValidationErrors(['due_dates']);
        $this->postPlan(array_diff_key($this->buildPlanPayload(), ['due_dates' => true]))->assertUnprocessable()->assertJsonValidationErrors(['due_dates']);
        $this->assertDatabaseCount('payment_plans', 0);

        $this->postPlan($this->buildPlanPayload(['due_dates' => array_slice($thirteenDates, 0, 12)]))->assertCreated()->assertJsonPath('payment_plan.installments_count', 12);
    }

    #[DataProvider('provideMalformedDueDates')]
    public function test_malformed_due_date_is_refused(mixed $malformedDate): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-07-15', $malformedDate]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.1']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_due_dates_must_be_distinct(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-07-15', '2030-07-15']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.0', 'due_dates.1']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_due_dates_must_be_strictly_increasing(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-08-15', '2030-07-15']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.1' => StorePaymentPlanRequest::DUE_DATES_ORDER_MESSAGE])
            ->assertJsonMissingValidationErrors(['due_dates.0']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_due_dates_must_fall_within_school_year(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-05-31', '2030-07-15']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.0' => StorePaymentPlanRequest::DUE_DATE_OUTSIDE_YEAR_MESSAGE])
            ->assertJsonMissingValidationErrors(['due_dates.1']);

        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-07-15', '2031-04-01']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.1' => StorePaymentPlanRequest::DUE_DATE_OUTSIDE_YEAR_MESSAGE]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_due_dates_on_school_year_bounds_are_accepted(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-06-01', '2031-03-31']]))
            ->assertCreated()
            ->assertJsonPath('payment_plan.installments.0.due_date', '2030-06-01')
            ->assertJsonPath('payment_plan.installments.1.due_date', '2031-03-31');
    }

    public function test_down_payment_is_required_with_installments(): void
    {
        $this->postPlan($this->buildPlanPayload(['down_payment' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['down_payment' => StorePaymentPlanRequest::DOWN_PAYMENT_REQUIRED_MESSAGE]);

        $withoutDownPayment = $this->buildPlanPayload();
        unset($withoutDownPayment['down_payment']);

        $this->postPlan($withoutDownPayment)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['down_payment' => StorePaymentPlanRequest::DOWN_PAYMENT_REQUIRED_MESSAGE]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_down_payment_is_refused_without_installments(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['down_payment' => StorePaymentPlanRequest::DOWN_PAYMENT_FORBIDDEN_MESSAGE]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_down_payment_type_must_be_known(): void
    {
        foreach (['Fixed', 'amount', null] as $unknownType) {
            $this->postPlan($this->buildPlanPayload(['down_payment' => ['type' => $unknownType, 'value' => '10']]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['down_payment.type']);
        }

        $this->assertDatabaseCount('payment_plans', 0);
    }

    #[DataProvider('provideInvalidDownPayments')]
    public function test_invalid_down_payment_value_is_refused(string $type, mixed $value): void
    {
        $this->postPlan($this->buildPlanPayload(['down_payment' => ['type' => $type, 'value' => $value]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['down_payment.value']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_down_payment_value_edges_pass(): void
    {
        $edgeCases = [
            [PaymentPlan::TYPE_FIXED, '0.01', '0.01'],
            [PaymentPlan::TYPE_FIXED, 9999999999.99, '9999999999.99'],
            [PaymentPlan::TYPE_PERCENT, '0.01', '0.01'],
            [PaymentPlan::TYPE_PERCENT, 99.99, '99.99'],
        ];

        foreach ($edgeCases as $edgeIndex => [$type, $value, $expectedValue]) {
            $this->postPlan($this->buildPlanPayload(['name' => 'Edge '.$edgeIndex, 'down_payment' => ['type' => $type, 'value' => $value]]))
                ->assertCreated()
                ->assertJsonPath('payment_plan.down_payment', ['type' => $type, 'value' => $expectedValue]);
        }
    }

    public function test_adjustment_is_optional(): void
    {
        $withoutAdjustment = $this->buildPlanPayload(['name' => 'Absent']);
        unset($withoutAdjustment['adjustment']);

        $this->postPlan($this->buildPlanPayload(['name' => 'Explicit null']))->assertCreated()->assertJsonPath('payment_plan.adjustment', null);
        $this->postPlan($withoutAdjustment)->assertCreated()->assertJsonPath('payment_plan.adjustment', null);
    }

    public function test_adjustment_kind_and_type_must_be_known(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => [], 'down_payment' => null, 'adjustment' => ['kind' => 'rebate', 'type' => 'amount', 'value' => '10']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adjustment.kind', 'adjustment.type']);

        $this->postPlan($this->buildPlanPayload(['due_dates' => [], 'down_payment' => null, 'adjustment' => ['kind' => null, 'type' => null, 'value' => '10']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adjustment.kind', 'adjustment.type']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    #[DataProvider('provideInvalidAdjustments')]
    public function test_invalid_adjustment_value_is_refused(string $type, mixed $value): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => [], 'down_payment' => null, 'adjustment' => ['kind' => PaymentPlan::KIND_DISCOUNT, 'type' => $type, 'value' => $value]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adjustment.value']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_adjustment_value_edges_pass(): void
    {
        $edgeCases = [
            [PaymentPlan::TYPE_PERCENT, 100, '100.00'],
            [PaymentPlan::TYPE_PERCENT, '0.01', '0.01'],
            [PaymentPlan::TYPE_FIXED, '0.01', '0.01'],
        ];

        foreach ($edgeCases as $edgeIndex => [$type, $value, $expectedValue]) {
            $this->postPlan($this->buildPlanPayload(['name' => 'Edge '.$edgeIndex, 'due_dates' => [], 'down_payment' => null, 'adjustment' => ['kind' => PaymentPlan::KIND_DISCOUNT, 'type' => $type, 'value' => $value]]))
                ->assertCreated()
                ->assertJsonPath('payment_plan.adjustment.value', $expectedValue);
        }
    }

    public function test_discount_is_refused_on_installment_plan(): void
    {
        $this->postPlan($this->buildPlanPayload(['adjustment' => ['kind' => PaymentPlan::KIND_DISCOUNT, 'type' => PaymentPlan::TYPE_PERCENT, 'value' => '5']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adjustment.kind' => StorePaymentPlanRequest::DISCOUNT_NOT_ALLOWED_MESSAGE]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_surcharge_is_refused_on_cash_plan(): void
    {
        $this->postPlan($this->buildPlanPayload(['due_dates' => [], 'down_payment' => null, 'adjustment' => ['kind' => PaymentPlan::KIND_SURCHARGE, 'type' => PaymentPlan::TYPE_FIXED, 'value' => '500']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adjustment.kind' => StorePaymentPlanRequest::SURCHARGE_NOT_ALLOWED_MESSAGE]);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_partial_objects_are_refused(): void
    {
        $this->postPlan($this->buildPlanPayload(['down_payment' => []]))->assertUnprocessable()->assertJsonValidationErrors(['down_payment']);
        $this->postPlan($this->buildPlanPayload(['down_payment' => ['type' => 'fixed']]))->assertUnprocessable()->assertJsonValidationErrors(['down_payment']);
        $this->postPlan($this->buildPlanPayload(['adjustment' => ['type' => 'fixed', 'value' => '10']]))->assertUnprocessable()->assertJsonValidationErrors(['adjustment']);

        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_cross_field_rules_wait_for_valid_inputs(): void
    {
        $this->createMonthlyPlan();

        $this->postPlan($this->buildPlanPayload(['due_dates' => 'nope', 'down_payment' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates'])
            ->assertJsonMissingValidationErrors(['down_payment']);

        $this->postPlan($this->buildPlanPayload(['school_year_id' => self::MISSING_ID, 'due_dates' => ['2030-05-01']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id'])
            ->assertJsonMissingValidationErrors(['name', 'due_dates.0']);

        $this->assertDatabaseCount('payment_plans', 1);
    }

    public function test_update_applies_the_same_rules(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $snapshot = $this->snapshotPlanTables();

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['due_dates' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['down_payment' => StorePaymentPlanRequest::DOWN_PAYMENT_FORBIDDEN_MESSAGE]);

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['due_dates' => ['2030-07-15', '2031-04-01']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_dates.1' => StorePaymentPlanRequest::DUE_DATE_OUTSIDE_YEAR_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_malformed_school_year_id_is_refused(): void
    {
        foreach (['05', true, 'abc', self::MISSING_ID] as $malformedId) {
            $this->postPlan($this->buildPlanPayload(['school_year_id' => $malformedId]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['school_year_id']);
        }

        $this->assertDatabaseCount('payment_plans', 0);
    }
}
