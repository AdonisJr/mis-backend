<?php

namespace Tests\Feature\Concerns;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\SchoolYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithPaymentPlans
{
    use InteractsWithFees;

    private const PAYMENT_PLANS_URL = '/api/mis/payment-plans';

    private const SCHOOL_YEAR_2032 = ['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2035 = ['name' => '2035-2036', 'start_date' => '2035-06-01', 'end_date' => '2036-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const CASH_PLAN = 'Cash';

    private const MONTHLY_PLAN = 'Monthly';

    private const QUARTERLY_PLAN = 'Quarterly';

    private const PLAN_KEYS = ['id', 'school_year_id', 'name', 'description', 'is_active', 'installments_count', 'installments', 'down_payment', 'adjustment', 'created_at', 'updated_at'];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildPlanPayload(array $overrides = []): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'name' => self::MONTHLY_PLAN,
            'description' => null,
            'due_dates' => ['2030-07-15', '2030-08-15'],
            'down_payment' => ['type' => PaymentPlan::TYPE_FIXED, 'value' => '1000.00'],
            'adjustment' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $planFields
     * @param  list<string>  $dueDates
     */
    private function createPlan(array $planFields, array $dueDates = []): PaymentPlan
    {
        $paymentPlan = PaymentPlan::factory()->create(['school_year_id' => $this->schoolYear->id, ...$planFields]);

        foreach ($dueDates as $dueDateIndex => $dueDate) {
            PaymentPlanInstallment::factory()->create(['payment_plan_id' => $paymentPlan->id, 'sequence' => $dueDateIndex + 1, 'due_date' => $dueDate]);
        }

        return $paymentPlan->load('installments');
    }

    /**
     * @param  array<string, mixed>  $planFields
     */
    private function createMonthlyPlan(array $planFields = []): PaymentPlan
    {
        return $this->createPlan([
            'name' => self::MONTHLY_PLAN,
            'down_payment_type' => PaymentPlan::TYPE_FIXED,
            'down_payment_value' => '1000.00',
            ...$planFields,
        ], ['2030-07-15', '2030-08-15']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postPlan(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::PAYMENT_PLANS_URL, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putPlan(int $planId, array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::PAYMENT_PLANS_URL.'/'.$planId, $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshotPlanTables(): array
    {
        return [DB::table('payment_plans')->orderBy('id')->get()->all(), DB::table('payment_plan_installments')->orderBy('id')->get()->all()];
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }
}
