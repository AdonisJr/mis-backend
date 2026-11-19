<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class PaymentRecordTest extends TestCase
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

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function provideInvalidAmounts(): array
    {
        return [
            'zero' => [['amount' => '0']],
            'negative' => [['amount' => '-5.00']],
            'three decimals' => [['amount' => '10.001']],
            'not numeric' => [['amount' => 'abc']],
            'over decimal(14,2) maximum' => [['amount' => '1000000000000.00']],
            'missing' => [[]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNonCashMethods(): array
    {
        return [
            'e-wallet' => [Payment::METHOD_E_WALLET],
            'bank transfer' => [Payment::METHOD_BANK_TRANSFER],
            'check' => [Payment::METHOD_CHECK],
        ];
    }

    public function test_payment_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('receipt_counters', ['id', 'series', 'last_number', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('payments', [
            'id', 'assessment_id', 'student_id', 'school_year_id', 'ar_number', 'amount', 'payment_date', 'method', 'reference_no', 'remarks',
            'balance_after', 'recorded_by', 'voided_at', 'voided_by', 'void_reason', 'created_at', 'updated_at',
        ]));

        $columns = collect(Schema::getColumns('payments'))->keyBy('name');
        $this->assertTrue($columns['reference_no']['nullable']);
        $this->assertTrue($columns['voided_at']['nullable']);
        $this->assertFalse($columns['ar_number']['nullable']);
        $this->assertFalse($columns['balance_after']['nullable']);
    }

    public function test_payment_foreign_keys_restrict_deletes(): void
    {
        $payment = Payment::factory()->voided()->create(['assessment_id' => $this->assessment['id']]);

        $restrictedRows = [
            ['assessments', $payment->assessment_id],
            ['students', $payment->student_id],
            ['school_years', $payment->school_year_id],
            ['users', $payment->recorded_by],
            ['users', $payment->voided_by],
        ];

        foreach ($restrictedRows as [$table, $rowId]) {
            try {
                DB::table($table)->where('id', $rowId)->delete();
                $this->fail('Deleting from '.$table.' should be restricted.');
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $rowId]);
            }
        }

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_cashier_records_payment_with_first_ar_number(): void
    {
        $response = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['remarks' => 'Down payment']))
            ->assertCreated()
            ->assertJsonPath('payment.ar_number', 'AR-000001')
            ->assertJsonPath('payment.amount', '1000.00')
            ->assertJsonPath('payment.balance_after', '4000.00')
            ->assertJsonPath('payment.status', Payment::STATUS_VALID)
            ->assertJsonPath('payment.assessment_id', $this->assessment['id'])
            ->assertJsonPath('payment.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('payment.student.id', $this->assessment['student_id'])
            ->assertJsonPath('payment.remarks', 'Down payment')
            ->assertJsonPath('payment.reference_no', null)
            ->assertJsonPath('payment.voided_by', null)
            ->assertJsonPath('payment.can_void', true);

        $payment = $response->json('payment');
        $cashier = User::where('email', self::CASHIER_EMAIL)->first();
        $this->assertSame(self::PAYMENT_ROW_KEYS, array_keys($payment));
        $this->assertSame(['id' => $cashier->id, 'name' => $cashier->name], $payment['recorded_by']);
        $this->assertTrue(Carbon::parse($payment['created_at'])->equalTo(now()));
        $this->assertSame(1, Payment::count());
        $this->assertSame($cashier->id, Payment::first()->recorded_by);
    }

    public function test_admin_records_payment(): void
    {
        $this->recordPayment($this->adminToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertCreated()
            ->assertJsonPath('payment.ar_number', 'AR-000001');

        $this->assertSame(User::where('email', 'acting@admin.test')->value('id'), Payment::first()->recorded_by);
    }

    public function test_ar_numbers_are_sequential_across_students(): void
    {
        $secondAssessment = $this->assessNewStudent($this->assessment);
        $thirdAssessment = $this->assessNewStudent($this->assessment);

        $arNumbers = [];
        foreach ([$this->assessment, $secondAssessment, $thirdAssessment] as $assessment) {
            $arNumbers[] = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($assessment['id']))->assertCreated()->json('payment.ar_number');
        }

        $this->assertSame(['AR-000001', 'AR-000002', 'AR-000003'], $arNumbers);
        $this->assertSame(3, DB::table('receipt_counters')->value('last_number'));
        $this->assertSame(1, DB::table('receipt_counters')->count());
    }

    public function test_failed_record_consumes_no_ar_number(): void
    {
        $snapshot = $this->snapshotPaymentTables();
        Payment::creating(fn () => throw new RuntimeException('Simulated failure after the AR number was allocated'));

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertStatus(500);

        Payment::flushEventListeners();
        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertCreated()
            ->assertJsonPath('payment.ar_number', 'AR-000001');
    }

    public function test_rejected_payment_consumes_no_ar_number(): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '0']))->assertUnprocessable();
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '5000.01']))->assertUnprocessable();
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload(self::MISSING_ID))->assertUnprocessable();

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertCreated()
            ->assertJsonPath('payment.ar_number', 'AR-000001');
    }

    public function test_voided_payment_keeps_its_ar_number_and_series_continues(): void
    {
        $firstId = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertCreated()->json('payment.id');
        $this->voidPayment($this->cashierToken, $firstId)->assertOk();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertCreated()
            ->assertJsonPath('payment.ar_number', 'AR-000002');

        $this->assertSame('AR-000001', Payment::find($firstId)->ar_number);
        $this->assertSame(2, Payment::count());
    }

    public function test_payment_date_defaults_to_today(): void
    {
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertCreated()
            ->assertJsonPath('payment.payment_date', self::ASSESSED_ON);
    }

    public function test_payment_date_accepts_today_and_past_but_not_future(): void
    {
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '100.00', 'payment_date' => '2030-07-01']))
            ->assertCreated()
            ->assertJsonPath('payment.payment_date', '2030-07-01');
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '100.00', 'payment_date' => '2030-06-30']))
            ->assertCreated()
            ->assertJsonPath('payment.payment_date', '2030-06-30');
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '100.00', 'payment_date' => '2030-07-02']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_date']);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    /**
     * @param  array<string, mixed>  $amountFields
     */
    #[DataProvider('provideInvalidAmounts')]
    public function test_invalid_amount_is_rejected(array $amountFields): void
    {
        $snapshot = $this->snapshotPaymentTables();
        $payload = [...Arr::except($this->buildPaymentPayload($this->assessment['id']), ['amount']), ...$amountFields];

        $this->recordPayment($this->cashierToken, $payload)->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_amounts_are_stored_and_summed_exactly(): void
    {
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '0.10']))
            ->assertCreated()
            ->assertJsonPath('payment.amount', '0.10')
            ->assertJsonPath('payment.balance_after', '4999.90');
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '0.20']))
            ->assertCreated()
            ->assertJsonPath('payment.balance_after', '4999.70');

        $this->sendAs($this->cashierToken, 'GET', self::ASSESSMENTS_URL.'/'.$this->assessment['id'])
            ->assertOk()
            ->assertJsonPath('assessment.total_paid', '0.30')
            ->assertJsonPath('assessment.balance', '4999.70');
    }

    #[DataProvider('provideNonCashMethods')]
    public function test_non_cash_method_requires_reference(string $method): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['method' => $method]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.reference_no.0', StorePaymentRequest::REFERENCE_REQUIRED_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['method' => $method, 'reference_no' => 'REF-1']))
            ->assertCreated()
            ->assertJsonPath('payment.reference_no', 'REF-1')
            ->assertJsonPath('payment.method', $method);
    }

    public function test_cash_reference_is_optional(): void
    {
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertCreated()->assertJsonPath('payment.reference_no', null);
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['reference_no' => 'OR-77']))
            ->assertCreated()
            ->assertJsonPath('payment.reference_no', 'OR-77');
    }

    public function test_method_must_be_in_fixed_list(): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['method' => 'cheque']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
        $this->recordPayment($this->cashierToken, Arr::except($this->buildPaymentPayload($this->assessment['id']), ['method']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_remarks_are_optional_and_limited_to_500(): void
    {
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '10.00']))->assertCreated()->assertJsonPath('payment.remarks', null);
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '10.00', 'remarks' => str_repeat('r', 500)]))->assertCreated();
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '10.00', 'remarks' => str_repeat('r', 501)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_assessment_must_exist(): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload(self::MISSING_ID))->assertUnprocessable()->assertJsonValidationErrors(['assessment_id']);
        $this->recordPayment($this->cashierToken, Arr::except($this->buildPaymentPayload($this->assessment['id']), ['assessment_id']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_id']);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_superseded_assessment_cannot_take_payments(): void
    {
        $this->postReassess($this->assessment['id'], PaymentPlan::findOrFail($this->assessment['payment_plan_id']))->assertCreated();
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.assessment_id.0', PaymentController::SUPERSEDED_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
        $this->assertDatabaseCount('receipt_counters', 0);
    }

    public function test_overpayment_is_rejected_and_exact_balance_is_accepted(): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '5000.01']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', PaymentController::OVERPAYMENT_MESSAGE);
        $this->assertEquals($snapshot, $this->snapshotPaymentTables());

        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '5000.00']))
            ->assertCreated()
            ->assertJsonPath('payment.balance_after', '0.00');
        $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => '0.01']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', PaymentController::OVERPAYMENT_MESSAGE);

        $this->assertSame(1, Payment::count());
    }

    public function test_payment_has_no_update_or_delete_route(): void
    {
        $paymentId = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertCreated()->json('payment.id');
        $snapshot = $this->snapshotPaymentTables();

        $this->sendAs($this->adminToken, 'PUT', self::PAYMENTS_URL.'/'.$paymentId, ['amount' => '1.00'])->assertNotFound();
        $this->sendAs($this->adminToken, 'DELETE', self::PAYMENTS_URL.'/'.$paymentId)->assertNotFound();
        $this->sendAs($this->adminToken, 'DELETE', self::PAYMENTS_URL)->assertStatus(405);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }
}
