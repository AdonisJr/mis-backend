<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Assessment;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class PaymentListTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $assessment;

    private User $cashier;

    private string $cashierToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->assessment = $this->assessForPayments('5000.00', ['2030-08-15', '2030-09-15']);
        $this->cashier = $this->createCashier(['name' => 'Mia Cashier']);
        $this->cashierToken = $this->createTokenFor($this->cashier);
    }

    public function test_list_defaults_to_today_by_payment_date(): void
    {
        $todayPayment = $this->createPayment();
        $this->createPayment(['payment_date' => '2030-06-30']);

        $listResponse = $this->fetchPayments($this->cashierToken)
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.id', $todayPayment->id)
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 1])
            ->assertJsonPath('collection_total', '100.00');

        $this->assertSame(self::PAYMENT_ROW_KEYS, array_keys($listResponse->json('payments.0')));
    }

    public function test_list_filters_by_date_range_inclusive_edges(): void
    {
        $dayBefore = $this->createPayment(['payment_date' => '2030-06-29']);
        $firstEdge = $this->createPayment(['payment_date' => '2030-06-30']);
        $secondEdge = $this->createPayment(['payment_date' => '2030-07-01']);
        $dayAfter = $this->createPayment(['payment_date' => '2030-07-02']);

        $this->assertSame(
            [$secondEdge->id, $firstEdge->id],
            $this->fetchListedIds(['date_from' => '2030-06-30', 'date_to' => '2030-07-01']),
        );
        $this->assertSame([$dayBefore->id], $this->fetchListedIds(['date_from' => '2030-06-29', 'date_to' => '2030-06-29']));
        $this->assertSame([$dayAfter->id], $this->fetchListedIds(['date_from' => '2030-07-02', 'date_to' => '2030-07-02']));
    }

    public function test_list_rejects_reversed_or_malformed_dates(): void
    {
        $this->fetchPayments($this->cashierToken, ['date_from' => '2030-07-02', 'date_to' => '2030-07-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
        $this->fetchPayments($this->cashierToken, ['date_from' => '01-07-2030', 'date_to' => '2030-07-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from']);
        $this->fetchPayments($this->cashierToken, ['date_from' => '2030-07-01', 'date_to' => 'tomorrow'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_list_filters_by_method(): void
    {
        $this->createPayment();
        $eWalletPayment = $this->createPayment(['method' => Payment::METHOD_E_WALLET, 'reference_no' => 'REF-1']);
        $this->createPayment(['method' => Payment::METHOD_CHECK, 'reference_no' => 'CHK-1']);

        $this->assertSame([$eWalletPayment->id], $this->fetchListedIds(['method' => Payment::METHOD_E_WALLET]));
    }

    public function test_list_filters_by_recorded_by(): void
    {
        $otherCashier = $this->createCashier(['name' => 'Beth Cashier', 'email' => 'beth.cashier@school.test']);
        $ownPayment = $this->createPayment(['recorded_by' => $this->cashier->id]);
        $othersPayment = $this->createPayment(['recorded_by' => $otherCashier->id]);

        $this->assertSame([$ownPayment->id], $this->fetchListedIds(['recorded_by' => $this->cashier->id]));
        $this->assertSame([$othersPayment->id], $this->fetchListedIds(['recorded_by' => $otherCashier->id]));
        $this->assertSame([$othersPayment->id, $ownPayment->id], $this->fetchListedIds());
    }

    public function test_list_filters_by_status(): void
    {
        $validPayment = $this->createPayment();
        $voidedPayment = Payment::factory()->voided()->create(['assessment_id' => $this->assessment['id']]);

        $this->assertSame([$validPayment->id], $this->fetchListedIds(['status' => Payment::STATUS_VALID]));
        $this->assertSame([$voidedPayment->id], $this->fetchListedIds(['status' => Payment::STATUS_VOIDED]));
        $this->assertSame([$voidedPayment->id, $validPayment->id], $this->fetchListedIds());
    }

    public function test_list_searches_student_name_lrn_and_ar_number(): void
    {
        $alicePayment = $this->createPaymentForStudent(['first_name' => 'Alice', 'last_name' => 'Zamora', 'lrn' => '111122223333'], 'AR-000777');
        $bobPayment = $this->createPaymentForStudent(['first_name' => 'Bob', 'last_name' => 'Yulo', 'lrn' => '444455556666'], 'AR-000888');

        $this->assertSame([$alicePayment->id], $this->fetchListedIds(['search' => 'alice']));
        $this->assertSame([$alicePayment->id], $this->fetchListedIds(['search' => 'ZAMORA alice']));
        $this->assertSame([$bobPayment->id], $this->fetchListedIds(['search' => '444455556666']));
        $this->assertSame([$bobPayment->id], $this->fetchListedIds(['search' => 'ar-000888']));
        $this->assertSame([], $this->fetchListedIds(['search' => 'alice yulo']));
        $this->assertSame([], $this->fetchListedIds(['search' => 'nobody']));
        $this->assertCount(2, $this->fetchListedIds(['search' => '  ']));
    }

    public function test_list_paginates_fifteen_per_page_newest_first(): void
    {
        $payments = Payment::factory()->count(16)->create(['assessment_id' => $this->assessment['id']]);
        $newestFirstIds = $payments->pluck('id')->reverse()->values()->all();

        $firstPage = $this->fetchPayments($this->cashierToken)->assertOk();
        $secondPage = $this->fetchPayments($this->cashierToken, ['page' => 2])->assertOk();

        $this->assertSame(array_slice($newestFirstIds, 0, 15), array_column($firstPage->json('payments'), 'id'));
        $this->assertSame(array_slice($newestFirstIds, 15), array_column($secondPage->json('payments'), 'id'));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 15, 'total' => 16], $firstPage->json('meta'));
        $this->assertSame(2, $secondPage->json('meta.current_page'));
    }

    public function test_collection_total_sums_non_voided_amounts_for_whole_filter(): void
    {
        Payment::factory()->count(16)->create(['assessment_id' => $this->assessment['id'], 'amount' => '10.10']);
        Payment::factory()->voided()->create(['assessment_id' => $this->assessment['id'], 'amount' => '999.00']);

        $this->fetchPayments($this->cashierToken)
            ->assertOk()
            ->assertJsonCount(15, 'payments')
            ->assertJsonPath('meta.total', 17)
            ->assertJsonPath('collection_total', '161.60');
        $this->fetchPayments($this->cashierToken, ['page' => 2])->assertOk()->assertJsonPath('collection_total', '161.60');
        $this->fetchPayments($this->cashierToken, ['status' => Payment::STATUS_VOIDED])->assertOk()->assertJsonPath('collection_total', '0.00');
        $this->fetchPayments($this->cashierToken, ['status' => Payment::STATUS_VALID])->assertOk()->assertJsonPath('collection_total', '161.60');
        $this->fetchPayments($this->cashierToken, ['date_from' => '2030-01-01', 'date_to' => '2030-01-02'])->assertOk()->assertJsonPath('collection_total', '0.00');
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $invalidQueries = [
            'method' => ['method' => 'cheque'],
            'recorded_by' => ['recorded_by' => 'abc'],
            'status' => ['status' => 'pending'],
            'search' => ['search' => str_repeat('s', 256)],
        ];

        foreach ($invalidQueries as $errorKey => $query) {
            $this->fetchPayments($this->cashierToken, $query)->assertUnprocessable()->assertJsonValidationErrors([$errorKey]);
        }

        $this->fetchPayments($this->cashierToken, ['recorded_by' => self::MISSING_ID])->assertUnprocessable()->assertJsonValidationErrors(['recorded_by']);
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $this->createPaymentForStudent();

        DB::enableQueryLog();
        $this->fetchPayments($this->cashierToken)->assertOk()->assertJsonCount(1, 'payments');
        $singleRowCount = $this->countQueriesWithoutTokens();

        foreach (range(1, 4) as $rowNumber) {
            $this->createPaymentForStudent(recordedBy: $this->createCashier(['email' => 'cashier'.$rowNumber.'@school.test'])->id);
        }

        DB::flushQueryLog();
        $this->fetchPayments($this->cashierToken)->assertOk()->assertJsonCount(5, 'payments');

        $this->assertSame($singleRowCount, $this->countQueriesWithoutTokens());
    }

    public function test_recorders_lists_admins_and_cashiers_by_name(): void
    {
        User::where('email', 'acting@admin.test')->update(['name' => 'Zoe Admin']);
        $inactiveCashier = $this->createCashier(['name' => 'Al Cashier', 'email' => 'al.cashier@school.test', 'is_active' => false]);
        $bethCashier = $this->createCashier(['name' => 'Beth Cashier', 'email' => 'beth.cashier@school.test']);
        User::factory()->registrar()->create(['name' => 'Ann Registrar']);
        User::factory()->create(['name' => 'Ben Roleless']);

        $recordersResponse = $this->sendAs($this->createRegistrarToken(), 'GET', self::PAYMENTS_URL.'/recorders')->assertOk();

        $this->assertSame(['recorders'], array_keys($recordersResponse->json()));
        $this->assertSame(['id', 'name'], array_keys($recordersResponse->json('recorders.0')));
        $this->assertSame(
            [
                ['id' => $inactiveCashier->id, 'name' => 'Al Cashier'],
                ['id' => $bethCashier->id, 'name' => 'Beth Cashier'],
                ['id' => $this->cashier->id, 'name' => 'Mia Cashier'],
                ['id' => User::where('email', 'acting@admin.test')->value('id'), 'name' => 'Zoe Admin'],
            ],
            $recordersResponse->json('recorders'),
        );
    }

    public function test_receipt_returns_every_field(): void
    {
        $paymentId = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['remarks' => 'Down payment']))->assertCreated()->json('payment.id');

        $receiptResponse = $this->sendAs($this->createRegistrarToken(), 'GET', self::PAYMENTS_URL.'/'.$paymentId.'/receipt')
            ->assertOk()
            ->assertJsonPath('receipt.id', $paymentId)
            ->assertJsonPath('receipt.ar_number', 'AR-000001')
            ->assertJsonPath('receipt.school_year', ['id' => $this->schoolYear->id, 'name' => '2030-2031'])
            ->assertJsonPath('receipt.grade_level.name', self::GRADE_7)
            ->assertJsonPath('receipt.program', null)
            ->assertJsonPath('receipt.can_void', false);

        $this->assertSame(['receipt'], array_keys($receiptResponse->json()));
        $this->assertSame(self::RECEIPT_KEYS, array_keys($receiptResponse->json('receipt')));
        $this->assertCount(21, $receiptResponse->json('receipt'));
    }

    public function test_receipt_of_voided_payment_shows_void_details(): void
    {
        $paymentId = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id']))->assertCreated()->json('payment.id');
        $this->voidPayment($this->cashierToken, $paymentId, 'Wrong student')->assertOk();

        $this->sendAs($this->cashierToken, 'GET', self::PAYMENTS_URL.'/'.$paymentId.'/receipt')
            ->assertOk()
            ->assertJsonPath('receipt.status', Payment::STATUS_VOIDED)
            ->assertJsonPath('receipt.void_reason', 'Wrong student')
            ->assertJsonPath('receipt.voided_by.id', $this->cashier->id)
            ->assertJsonPath('receipt.can_void', false);
    }

    public function test_receipt_missing_id_returns_404(): void
    {
        $this->sendAs($this->cashierToken, 'GET', self::PAYMENTS_URL.'/'.self::MISSING_ID.'/receipt')->assertNotFound();
    }

    public function test_ledger_requires_student_and_school_year(): void
    {
        $this->sendAs($this->cashierToken, 'GET', self::PAYMENTS_URL.'/ledger')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id', 'school_year_id']);
        $this->sendAs($this->cashierToken, 'GET', self::PAYMENTS_URL.'/ledger?student_id='.self::MISSING_ID.'&school_year_id='.self::MISSING_ID)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['student_id', 'school_year_id']);
    }

    public function test_ledger_without_current_assessment_returns_404(): void
    {
        $student = Student::factory()->create();

        $this->sendAs($this->cashierToken, 'GET', self::PAYMENTS_URL.'/ledger?student_id='.$student->id.'&school_year_id='.$this->schoolYear->id)
            ->assertNotFound()
            ->assertExactJson(['message' => PaymentController::NO_CURRENT_ASSESSMENT_MESSAGE]);
    }

    public function test_ledger_lists_payments_newest_first_including_voided(): void
    {
        $paymentIds = [];
        foreach (['100.00', '200.00', '300.00'] as $amount) {
            $paymentIds[] = $this->recordPayment($this->cashierToken, $this->buildPaymentPayload($this->assessment['id'], ['amount' => $amount]))->assertCreated()->json('payment.id');
        }
        $this->voidPayment($this->cashierToken, $paymentIds[1])->assertOk();

        $ledgerResponse = $this->fetchLedger($this->cashierToken, $this->assessment)->assertOk();
        $ledger = $ledgerResponse->json('ledger');

        $this->assertSame(self::LEDGER_KEYS, array_keys($ledger));
        $this->assertSame(array_reverse($paymentIds), array_column($ledger['payments'], 'id'));
        $this->assertSame([Payment::STATUS_VALID, Payment::STATUS_VOIDED, Payment::STATUS_VALID], array_column($ledger['payments'], 'status'));
        $this->assertSame(self::PAYMENT_ROW_KEYS, array_keys($ledger['payments'][0]));
        $this->assertSame(self::SCHEDULE_ROW_KEYS, array_keys($ledger['schedule'][0]));
        $this->assertSame($this->assessment['id'], $ledger['assessment_id']);
        $this->assertSame('5000.00', $ledger['net_total']);
        $this->assertSame('400.00', $ledger['total_paid']);
        $this->assertSame('4600.00', $ledger['balance']);
    }

    public function test_ledger_lists_notes_of_that_year_newest_first_with_status(): void
    {
        $noteFields = ['student_id' => $this->assessment['student_id'], 'school_year_id' => $this->assessment['school_year_id'], 'promise_date' => '2030-07-15'];
        $activeNote = PromissoryNote::factory()->create($noteFields);
        $cancelledNote = PromissoryNote::factory()->cancelled()->create($noteFields);
        PromissoryNote::factory()->create([...$noteFields, 'school_year_id' => SchoolYear::factory()->create(self::SCHOOL_YEAR_2031)->id]);
        PromissoryNote::factory()->create([...$noteFields, 'student_id' => Student::factory()->create()->id]);

        $ledgerNotes = $this->fetchLedger($this->cashierToken, $this->assessment)->assertOk()->json('ledger.promissory_notes');

        $this->assertSame([$cancelledNote->id, $activeNote->id], array_column($ledgerNotes, 'id'));
        $this->assertSame([PromissoryNote::STATUS_CANCELLED, PromissoryNote::STATUS_ACTIVE], array_column($ledgerNotes, 'status'));
        $this->assertSame(['id', 'student_id', 'school_year_id', 'student', 'school_year', 'promised_amount', 'promise_date', 'remarks', 'status', 'granted_by', 'granted_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'], array_keys($ledgerNotes[0]));
    }

    /**
     * @param  array<string, mixed>  $paymentFields
     */
    private function createPayment(array $paymentFields = []): Payment
    {
        return Payment::factory()->create(['assessment_id' => $this->assessment['id'], ...$paymentFields]);
    }

    /**
     * @param  array<string, mixed>  $studentFields
     */
    private function createPaymentForStudent(array $studentFields = [], ?string $arNumber = null, ?int $recordedBy = null): Payment
    {
        $assessment = Assessment::factory()->create(['student_id' => Student::factory()->create($studentFields)->id, 'school_year_id' => $this->schoolYear->id]);

        return Payment::factory()->create(array_filter([
            'assessment_id' => $assessment->id,
            'ar_number' => $arNumber,
            'recorded_by' => $recordedBy,
        ]));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function fetchListedIds(array $query = []): array
    {
        return array_column($this->fetchPayments($this->cashierToken, $query)->assertOk()->json('payments'), 'id');
    }
}
