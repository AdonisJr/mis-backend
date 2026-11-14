<?php

namespace Tests\Feature;

use App\Http\Requests\UpdatePaymentPlanRequest;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithPaymentPlans;
use Tests\TestCase;

class PaymentPlanCrudTest extends TestCase
{
    use InteractsWithPaymentPlans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_payment_plan_tables_have_expected_columns(): void
    {
        $this->assertSame(
            ['id', 'school_year_id', 'name', 'description', 'is_active', 'down_payment_type', 'down_payment_value', 'adjustment_kind', 'adjustment_type', 'adjustment_value', 'created_at', 'updated_at'],
            Schema::getColumnListing('payment_plans'),
        );
        $this->assertSame(['id', 'payment_plan_id', 'sequence', 'due_date', 'created_at', 'updated_at'], Schema::getColumnListing('payment_plan_installments'));

        $planColumns = collect(Schema::getColumns('payment_plans'))->keyBy('name');
        $this->assertFalse($planColumns['school_year_id']['nullable']);
        $this->assertFalse($planColumns['name']['nullable']);
        $this->assertFalse($planColumns['is_active']['nullable']);
        $this->assertTrue($planColumns['description']['nullable']);

        foreach (['down_payment_type', 'down_payment_value', 'adjustment_kind', 'adjustment_type', 'adjustment_value'] as $nullableColumn) {
            $this->assertTrue($planColumns[$nullableColumn]['nullable'], $nullableColumn);
        }

        $installmentColumns = collect(Schema::getColumns('payment_plan_installments'))->keyBy('name');
        $this->assertFalse($installmentColumns['sequence']['nullable']);
        $this->assertFalse($installmentColumns['due_date']['nullable']);
    }

    public function test_constraints_restrict_school_year_cascade_installments_and_keep_rows_unique(): void
    {
        $planForeignKeys = collect(Schema::getForeignKeys('payment_plans'))->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])->all();
        $installmentForeignKeys = collect(Schema::getForeignKeys('payment_plan_installments'))->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])->all();

        $this->assertEquals(['school_year_id' => ['school_years', 'restrict']], $planForeignKeys);
        $this->assertEquals(['payment_plan_id' => ['payment_plans', 'cascade']], $installmentForeignKeys);

        $paymentPlan = $this->createMonthlyPlan();

        $this->assertThrows(fn () => DB::table('school_years')->where('id', $this->schoolYear->id)->delete(), QueryException::class);
        $this->assertThrows(fn () => PaymentPlanInstallment::create(['payment_plan_id' => $paymentPlan->id, 'sequence' => 1, 'due_date' => '2030-09-15']), QueryException::class);
        $this->assertThrows(fn () => PaymentPlanInstallment::create(['payment_plan_id' => $paymentPlan->id, 'sequence' => 3, 'due_date' => '2030-07-15']), QueryException::class);
        $this->assertDatabaseCount('payment_plan_installments', 2);

        DB::table('payment_plans')->where('id', $paymentPlan->id)->delete();
        $this->assertDatabaseCount('payment_plan_installments', 0);
    }

    public function test_admin_creates_cash_plan(): void
    {
        $createResponse = $this->postPlan($this->buildPlanPayload(['name' => self::CASH_PLAN, 'description' => 'Pay in full', 'due_dates' => [], 'down_payment' => null]))
            ->assertCreated()
            ->assertJsonPath('payment_plan.name', self::CASH_PLAN)
            ->assertJsonPath('payment_plan.description', 'Pay in full')
            ->assertJsonPath('payment_plan.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('payment_plan.is_active', true)
            ->assertJsonPath('payment_plan.installments', [])
            ->assertJsonPath('payment_plan.installments_count', 0)
            ->assertJsonPath('payment_plan.down_payment', null)
            ->assertJsonPath('payment_plan.adjustment', null);

        $this->assertSame(self::PLAN_KEYS, array_keys($createResponse->json('payment_plan')));
        $this->assertDatabaseCount('payment_plans', 1);
        $this->assertDatabaseCount('payment_plan_installments', 0);
    }

    public function test_admin_creates_installment_plan_with_down_payment_and_surcharge(): void
    {
        $adjustment = ['kind' => PaymentPlan::KIND_SURCHARGE, 'type' => PaymentPlan::TYPE_FIXED, 'value' => '500.00'];

        $createResponse = $this->postPlan($this->buildPlanPayload(['due_dates' => ['2030-07-15', '2030-08-15', '2030-09-15'], 'adjustment' => $adjustment]))
            ->assertCreated()
            ->assertJsonPath('payment_plan.installments_count', 3)
            ->assertJsonPath('payment_plan.installments.0', ['sequence' => 1, 'due_date' => '2030-07-15'])
            ->assertJsonPath('payment_plan.installments.2', ['sequence' => 3, 'due_date' => '2030-09-15'])
            ->assertJsonPath('payment_plan.down_payment', ['type' => 'fixed', 'value' => '1000.00'])
            ->assertJsonPath('payment_plan.adjustment', [...$adjustment, 'category' => 'tuition']);

        $this->assertSame(self::PLAN_KEYS, array_keys($createResponse->json('payment_plan')));
        $this->assertSame([1, 2, 3], PaymentPlanInstallment::orderBy('id')->pluck('sequence')->all());
    }

    public function test_create_accepts_numeric_strings_and_returns_two_decimal_values(): void
    {
        $this->postPlan($this->buildPlanPayload([
            'school_year_id' => (string) $this->schoolYear->id,
            'down_payment' => ['type' => PaymentPlan::TYPE_PERCENT, 'value' => '20'],
            'adjustment' => ['kind' => PaymentPlan::KIND_SURCHARGE, 'type' => PaymentPlan::TYPE_PERCENT, 'value' => 5.5],
        ]))
            ->assertCreated()
            ->assertJsonPath('payment_plan.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('payment_plan.down_payment', ['type' => 'percent', 'value' => '20.00'])
            ->assertJsonPath('payment_plan.adjustment.value', '5.50');
    }

    public function test_create_ignores_is_active_and_unknown_keys(): void
    {
        $this->postPlan($this->buildPlanPayload(['is_active' => false, 'id' => 999, 'unknown' => 'value']))
            ->assertCreated()
            ->assertJsonPath('payment_plan.is_active', true)
            ->assertJsonMissingPath('payment_plan.unknown');

        $this->assertNotSame(999, PaymentPlan::first()->id);
        $this->assertTrue(PaymentPlan::first()->is_active);
    }

    public function test_show_returns_plan_with_ordered_installments(): void
    {
        $paymentPlan = $this->createMonthlyPlan(['description' => 'Two parts']);

        $showResponse = $this->withToken($this->adminToken)
            ->getJson(self::PAYMENT_PLANS_URL.'/'.$paymentPlan->id)
            ->assertOk()
            ->assertJsonPath('payment_plan.id', $paymentPlan->id)
            ->assertJsonPath('payment_plan.description', 'Two parts')
            ->assertJsonPath('payment_plan.installments', [['sequence' => 1, 'due_date' => '2030-07-15'], ['sequence' => 2, 'due_date' => '2030-08-15']])
            ->assertJsonPath('payment_plan.down_payment', ['type' => 'fixed', 'value' => '1000.00']);

        $this->assertSame(self::PLAN_KEYS, array_keys($showResponse->json('payment_plan')));
    }

    public function test_show_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::PAYMENT_PLANS_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_admin_updates_plan_and_replaces_installments_as_a_set(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $oldInstallmentIds = $paymentPlan->installments->pluck('id')->all();

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload([
            'name' => self::QUARTERLY_PLAN,
            'description' => 'Three parts',
            'due_dates' => ['2030-08-01', '2030-10-01', '2030-12-01'],
            'down_payment' => ['type' => PaymentPlan::TYPE_PERCENT, 'value' => '25'],
            'adjustment' => ['kind' => PaymentPlan::KIND_SURCHARGE, 'type' => PaymentPlan::TYPE_FIXED, 'value' => '300'],
        ]))
            ->assertOk()
            ->assertJsonPath('payment_plan.id', $paymentPlan->id)
            ->assertJsonPath('payment_plan.name', self::QUARTERLY_PLAN)
            ->assertJsonPath('payment_plan.installments_count', 3)
            ->assertJsonPath('payment_plan.installments.1', ['sequence' => 2, 'due_date' => '2030-10-01'])
            ->assertJsonPath('payment_plan.down_payment', ['type' => 'percent', 'value' => '25.00'])
            ->assertJsonPath('payment_plan.adjustment.value', '300.00');

        $this->assertSame(3, PaymentPlanInstallment::count());
        $this->assertSame([], array_intersect($oldInstallmentIds, PaymentPlanInstallment::pluck('id')->all()));
        $this->assertSame(1, PaymentPlan::count());
    }

    public function test_update_can_turn_installment_plan_into_cash_plan_with_discount(): void
    {
        $paymentPlan = $this->createMonthlyPlan();

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload([
            'name' => self::CASH_PLAN,
            'due_dates' => [],
            'down_payment' => null,
            'adjustment' => ['kind' => PaymentPlan::KIND_DISCOUNT, 'type' => PaymentPlan::TYPE_PERCENT, 'value' => '10'],
        ]))
            ->assertOk()
            ->assertJsonPath('payment_plan.installments', [])
            ->assertJsonPath('payment_plan.installments_count', 0)
            ->assertJsonPath('payment_plan.down_payment', null)
            ->assertJsonPath('payment_plan.adjustment', ['kind' => 'discount', 'type' => 'percent', 'value' => '10.00', 'category' => 'tuition']);

        $this->assertDatabaseCount('payment_plan_installments', 0);
        $this->assertNull($paymentPlan->fresh()->down_payment_type);
    }

    public function test_update_keeps_is_active_and_edits_inactive_plan(): void
    {
        $paymentPlan = $this->createMonthlyPlan(['is_active' => false]);

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['name' => self::QUARTERLY_PLAN, 'is_active' => true]))
            ->assertOk()
            ->assertJsonPath('payment_plan.name', self::QUARTERLY_PLAN)
            ->assertJsonPath('payment_plan.is_active', false);

        $this->assertFalse($paymentPlan->fresh()->is_active);
    }

    public function test_update_rejects_school_year_change(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $snapshot = $this->snapshotPlanTables();

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['school_year_id' => $otherYear->id, 'name' => self::QUARTERLY_PLAN]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => UpdatePaymentPlanRequest::SCHOOL_YEAR_LOCKED_MESSAGE]);

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['school_year_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id' => UpdatePaymentPlanRequest::SCHOOL_YEAR_LOCKED_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_update_accepts_same_or_absent_school_year(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $absentYearPayload = $this->buildPlanPayload();
        unset($absentYearPayload['school_year_id']);

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload())->assertOk();
        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['school_year_id' => (string) $this->schoolYear->id]))->assertOk();
        $this->putPlan($paymentPlan->id, $absentYearPayload)->assertOk()->assertJsonPath('payment_plan.school_year_id', $this->schoolYear->id);
    }

    public function test_update_allows_own_name_in_another_case(): void
    {
        $paymentPlan = $this->createMonthlyPlan();

        $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['name' => strtoupper(self::MONTHLY_PLAN)]))
            ->assertOk()
            ->assertJsonPath('payment_plan.name', strtoupper(self::MONTHLY_PLAN));
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->putPlan(self::MISSING_ID, ['name' => '', 'due_dates' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'due_dates']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->putPlan(self::MISSING_ID, $this->buildPlanPayload())->assertNotFound();
    }

    public function test_update_rolls_back_when_installment_insert_fails(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $snapshot = $this->snapshotPlanTables();
        $createdCount = 0;
        PaymentPlanInstallment::created(function () use (&$createdCount) {
            $createdCount++;

            if ($createdCount === 2) {
                throw new RuntimeException('Simulated write failure');
            }
        });

        try {
            $this->putPlan($paymentPlan->id, $this->buildPlanPayload(['name' => self::QUARTERLY_PLAN, 'due_dates' => ['2030-09-01', '2030-10-01', '2030-11-01']]))->assertStatus(500);
        } finally {
            PaymentPlanInstallment::flushEventListeners();
        }

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    public function test_admin_deactivates_and_reactivates_plan(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $planUrl = self::PAYMENT_PLANS_URL.'/'.$paymentPlan->id;

        foreach ([false, false, true, true] as $expectedState) {
            $statusAction = $expectedState ? '/reactivate' : '/deactivate';

            $this->withToken($this->adminToken)
                ->putJson($planUrl.$statusAction)
                ->assertOk()
                ->assertJsonPath('payment_plan.is_active', $expectedState)
                ->assertJsonPath('payment_plan.installments_count', 2);

            $this->assertSame($expectedState, $paymentPlan->fresh()->is_active);
        }
    }

    public function test_status_actions_ignore_body(): void
    {
        $paymentPlan = $this->createMonthlyPlan();

        $this->withToken($this->adminToken)
            ->putJson(self::PAYMENT_PLANS_URL.'/'.$paymentPlan->id.'/deactivate', ['name' => 'Changed', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('payment_plan.is_active', false)
            ->assertJsonPath('payment_plan.name', self::MONTHLY_PLAN);

        $this->assertSame(self::MONTHLY_PLAN, $paymentPlan->fresh()->name);
    }

    public function test_status_routes_missing_id_return_404(): void
    {
        $this->withToken($this->adminToken)->putJson(self::PAYMENT_PLANS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::PAYMENT_PLANS_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_admin_deletes_plan_and_its_installments(): void
    {
        $paymentPlan = $this->createMonthlyPlan();
        $otherPlan = $this->createPlan(['name' => self::QUARTERLY_PLAN, 'down_payment_type' => 'fixed', 'down_payment_value' => '10.00'], ['2030-09-01']);

        $this->withToken($this->adminToken)->deleteJson(self::PAYMENT_PLANS_URL.'/'.$paymentPlan->id)->assertNoContent();

        $this->assertNull($paymentPlan->fresh());
        $this->assertDatabaseCount('payment_plan_installments', 1);
        $this->assertNotNull($otherPlan->fresh());
    }

    public function test_delete_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::PAYMENT_PLANS_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_list_requires_school_year(): void
    {
        $this->withToken($this->adminToken)->getJson(self::PAYMENT_PLANS_URL)->assertUnprocessable()->assertJsonValidationErrors(['school_year_id']);
        $this->withToken($this->adminToken)->getJson(self::PAYMENT_PLANS_URL.'?school_year_id='.self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_returns_plans_of_school_year_ordered_by_name(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createMonthlyPlan();
        $this->createPlan(['name' => self::CASH_PLAN]);
        $this->createPlan(['name' => self::QUARTERLY_PLAN, 'school_year_id' => $otherYear->id]);

        $listResponse = $this->fetchPlanList()
            ->assertOk()
            ->assertJsonCount(2, 'payment_plans')
            ->assertJsonPath('payment_plans.0.name', self::CASH_PLAN)
            ->assertJsonPath('payment_plans.1.name', self::MONTHLY_PLAN)
            ->assertJsonPath('payment_plans.1.installments_count', 2)
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 2]);

        $this->assertSame(self::PLAN_KEYS, array_keys($listResponse->json('payment_plans.0')));
    }

    public function test_list_searches_every_word_in_name(): void
    {
        $this->createPlan(['name' => 'Monthly Plan A']);
        $this->createPlan(['name' => 'Quarterly Plan B']);
        $this->createPlan(['name' => 'Cash']);

        $this->fetchPlanList('&search=month')->assertOk()->assertJsonCount(1, 'payment_plans')->assertJsonPath('payment_plans.0.name', 'Monthly Plan A');
        $this->fetchPlanList('&search=PLAN%20quart')->assertOk()->assertJsonCount(1, 'payment_plans')->assertJsonPath('payment_plans.0.name', 'Quarterly Plan B');
        $this->fetchPlanList('&search=plan')->assertOk()->assertJsonCount(2, 'payment_plans');
        $this->fetchPlanList('&search=month%20quart')->assertOk()->assertJsonCount(0, 'payment_plans');
    }

    public function test_list_filters_by_status_and_lists_inactive_by_default(): void
    {
        $this->createPlan(['name' => self::CASH_PLAN]);
        $this->createPlan(['name' => self::MONTHLY_PLAN, 'is_active' => false]);

        $this->fetchPlanList()->assertOk()->assertJsonCount(2, 'payment_plans');
        $this->fetchPlanList('&status=active')->assertOk()->assertJsonCount(1, 'payment_plans')->assertJsonPath('payment_plans.0.name', self::CASH_PLAN);
        $this->fetchPlanList('&status=inactive')->assertOk()->assertJsonCount(1, 'payment_plans')->assertJsonPath('payment_plans.0.name', self::MONTHLY_PLAN);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->fetchPlanList('&status=archived')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->fetchPlanList('&search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        PaymentPlan::factory()->count(16)->create(['school_year_id' => $this->schoolYear->id]);

        $this->fetchPlanList()->assertOk()->assertJsonCount(15, 'payment_plans')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
        $this->fetchPlanList('&page=2')->assertOk()->assertJsonCount(1, 'payment_plans')->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        PaymentPlan::factory()->withInstallments(3)->create(['school_year_id' => $this->schoolYear->id]);

        DB::enableQueryLog();
        $this->fetchPlanList()->assertOk()->assertJsonCount(1, 'payment_plans');
        $singleRowCount = $this->countNonTokenQueries();

        PaymentPlan::factory()->withInstallments(2)->count(3)->create(['school_year_id' => $this->schoolYear->id]);
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchPlanList()->assertOk()->assertJsonCount(4, 'payment_plans');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    private function fetchPlanList(string $queryString = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::PAYMENT_PLANS_URL.'?school_year_id='.$this->schoolYear->id.$queryString);
    }
}
