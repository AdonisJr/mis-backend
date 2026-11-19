<?php

namespace Tests\Feature\Concerns;

use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait InteractsWithPayments
{
    use InteractsWithAssessments;

    private const PAYMENTS_URL = '/api/mis/payments';

    private const MANILA = 'Asia/Manila';

    private const CASHIER_EMAIL = 'cashier@school.test';

    private const PAYMENT_ROW_KEYS = [
        'id', 'ar_number', 'assessment_id', 'school_year_id', 'student', 'payment_date', 'amount', 'method', 'reference_no', 'remarks',
        'balance_after', 'status', 'recorded_by', 'created_at', 'voided_at', 'voided_by', 'void_reason', 'can_void',
    ];

    private const RECEIPT_KEYS = [...self::PAYMENT_ROW_KEYS, 'school_year', 'grade_level', 'program'];

    private const LEDGER_KEYS = [
        'assessment_id', 'student', 'school_year', 'grade_level', 'program', 'plan_name', 'net_total', 'total_paid', 'balance', 'next_amount_due', 'schedule', 'payments',
    ];

    private const SCHEDULE_ROW_KEYS = ['sequence', 'label', 'due_date', 'amount', 'paid', 'status', 'is_overdue'];

    /**
     * @param  array<string, mixed>  $userFields
     */
    private function createCashier(array $userFields = []): User
    {
        return User::factory()->cashier()->create(['email' => self::CASHIER_EMAIL, ...$userFields]);
    }

    private function createCashierToken(): string
    {
        return $this->createTokenFor($this->createCashier());
    }

    /**
     * A Grade 7 student assessed on a plan with the given due dates (down payment 1000.00), through the endpoint.
     *
     * @param  list<string>  $dueDates
     * @return array<string, mixed>
     */
    private function assessForPayments(string $tuitionAmount, array $dueDates): array
    {
        return $this->assessWithTuition($tuitionAmount, $this->createInstallmentPlan($dueDates));
    }

    /**
     * Another student on the same plan; the fee schedule of the first assessment already exists.
     *
     * @param  array<string, mixed>  $assessment
     * @return array<string, mixed>
     */
    private function assessNewStudent(array $assessment): array
    {
        return $this->assessStudent($this->createEnrolledStudent(GradeLevel::STAGE_JHS), PaymentPlan::findOrFail($assessment['payment_plan_id']));
    }

    /**
     * @param  array<string, mixed>  $paymentFields
     * @return array<string, mixed>
     */
    private function buildPaymentPayload(int $assessmentId, array $paymentFields = []): array
    {
        return ['assessment_id' => $assessmentId, 'amount' => '1000.00', 'method' => 'cash', ...$paymentFields];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendAs(string $token, string $method, string $uri, array $payload = []): TestResponse
    {
        // a different token needs a fresh guard, which otherwise keeps the user of the previous request
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordPayment(string $token, array $payload): TestResponse
    {
        return $this->sendAs($token, 'POST', self::PAYMENTS_URL, $payload);
    }

    private function voidPayment(string $token, int $paymentId, string $reason = 'Wrong amount'): TestResponse
    {
        return $this->sendAs($token, 'PUT', self::PAYMENTS_URL.'/'.$paymentId.'/void', ['void_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $assessment
     */
    private function fetchLedger(string $token, array $assessment): TestResponse
    {
        return $this->sendAs($token, 'GET', self::PAYMENTS_URL.'/ledger?student_id='.$assessment['student_id'].'&school_year_id='.$assessment['school_year_id']);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fetchPayments(string $token, array $query = []): TestResponse
    {
        return $this->sendAs($token, 'GET', self::PAYMENTS_URL.'?'.http_build_query($query));
    }

    /**
     * @return list<array<int, object>>
     */
    private function snapshotPaymentTables(): array
    {
        return array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->all(),
            ['payments', 'receipt_counters'],
        );
    }
}
