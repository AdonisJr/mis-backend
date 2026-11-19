<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class PaymentVoidTest extends TestCase
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
        $this->cashier = $this->createCashier();
        $this->cashierToken = $this->createTokenFor($this->cashier);
    }

    public function test_cashier_voids_own_same_day_payment(): void
    {
        $paymentId = $this->pay($this->cashierToken);
        $this->travelToManila('2030-07-01 16:00:00');

        $this->voidPayment($this->cashierToken, $paymentId, 'Entered twice')
            ->assertOk()
            ->assertJsonPath('payment.status', Payment::STATUS_VOIDED)
            ->assertJsonPath('payment.void_reason', 'Entered twice')
            ->assertJsonPath('payment.voided_by', ['id' => $this->cashier->id, 'name' => $this->cashier->name])
            ->assertJsonPath('payment.ar_number', 'AR-000001')
            ->assertJsonPath('payment.can_void', false);

        $voidedPayment = Payment::findOrFail($paymentId);
        $this->assertNotNull($voidedPayment->voided_at);
        $this->assertSame($this->cashier->id, $voidedPayment->voided_by);
        $this->assertSame('AR-000001', $voidedPayment->ar_number);
        $this->assertSame(1, Payment::count());
    }

    public function test_void_requires_reason(): void
    {
        $paymentId = $this->pay($this->cashierToken);

        $this->sendAs($this->cashierToken, 'PUT', self::PAYMENTS_URL.'/'.$paymentId.'/void', [])->assertUnprocessable()->assertJsonValidationErrors(['void_reason']);
        $this->voidPayment($this->cashierToken, $paymentId, str_repeat('r', 501))->assertUnprocessable()->assertJsonValidationErrors(['void_reason']);

        $this->assertNull(Payment::findOrFail($paymentId)->voided_at);
    }

    public function test_voided_payment_cannot_be_voided_again(): void
    {
        $paymentId = $this->pay($this->cashierToken);
        $this->voidPayment($this->cashierToken, $paymentId, 'First reason')->assertOk();
        $snapshot = $this->snapshotPaymentTables();

        $this->voidPayment($this->cashierToken, $paymentId, 'Second reason')
            ->assertUnprocessable()
            ->assertJsonPath('errors.payment.0', PaymentController::ALREADY_VOIDED_MESSAGE);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
        $this->assertSame('First reason', Payment::findOrFail($paymentId)->void_reason);
    }

    public function test_cashier_cannot_void_another_users_payment(): void
    {
        $otherCashierToken = $this->createTokenFor($this->createCashier(['email' => 'other.cashier@school.test']));
        $paymentId = $this->pay($otherCashierToken);
        $snapshot = $this->snapshotPaymentTables();

        $this->voidPayment($this->cashierToken, $paymentId)
            ->assertForbidden()
            ->assertExactJson(['message' => PaymentController::VOID_NOT_ALLOWED_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_cashier_cannot_void_own_payment_from_previous_day(): void
    {
        $paymentId = $this->pay($this->cashierToken);
        $this->travelToManila('2030-07-02 09:00:00');
        $snapshot = $this->snapshotPaymentTables();

        $this->voidPayment($this->cashierToken, $paymentId)
            ->assertForbidden()
            ->assertExactJson(['message' => PaymentController::VOID_NOT_ALLOWED_MESSAGE]);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_same_day_uses_manila_calendar_day(): void
    {
        // 07:30 Manila is still the previous calendar day in UTC
        $this->travelToManila('2030-07-01 07:30:00');
        $earlyPaymentId = $this->pay($this->cashierToken);
        $this->travelToManila('2030-07-01 17:00:00');
        $this->voidPayment($this->cashierToken, $earlyPaymentId)->assertOk();

        $this->travelToManila('2030-07-01 23:59:59');
        $latePaymentId = $this->pay($this->cashierToken);
        $this->travelToManila('2030-07-02 00:00:00');
        $this->voidPayment($this->cashierToken, $latePaymentId)->assertForbidden();

        $this->assertNull(Payment::findOrFail($latePaymentId)->voided_at);
    }

    public function test_admin_voids_any_payment_from_any_day(): void
    {
        $paymentId = $this->pay($this->cashierToken);
        $this->travelToManila('2030-07-06 09:00:00');
        $adminId = User::where('email', 'acting@admin.test')->value('id');

        $this->voidPayment($this->adminToken, $paymentId)
            ->assertOk()
            ->assertJsonPath('payment.voided_by.id', $adminId);

        $this->assertSame($adminId, Payment::findOrFail($paymentId)->voided_by);
    }

    public function test_void_missing_payment_returns_404(): void
    {
        $this->voidPayment($this->cashierToken, self::MISSING_ID)->assertNotFound();
    }

    public function test_can_void_flag_follows_role_owner_and_day(): void
    {
        $otherCashier = $this->createCashier(['email' => 'other.cashier@school.test']);
        $assessmentId = $this->assessment['id'];
        $ownToday = Payment::factory()->create(['assessment_id' => $assessmentId, 'recorded_by' => $this->cashier->id]);
        $ownYesterday = Payment::factory()->create(['assessment_id' => $assessmentId, 'recorded_by' => $this->cashier->id, 'created_at' => now()->subDay()]);
        $othersToday = Payment::factory()->create(['assessment_id' => $assessmentId, 'recorded_by' => $otherCashier->id]);
        $ownVoided = Payment::factory()->voided()->create(['assessment_id' => $assessmentId, 'recorded_by' => $this->cashier->id]);
        $ids = [$ownToday->id, $ownYesterday->id, $othersToday->id, $ownVoided->id];

        $this->assertSame(
            [$ownToday->id => true, $ownYesterday->id => false, $othersToday->id => false, $ownVoided->id => false],
            $this->mapCanVoid($this->cashierToken, $ids),
        );
        $this->assertSame(
            [$ownToday->id => true, $ownYesterday->id => true, $othersToday->id => true, $ownVoided->id => false],
            $this->mapCanVoid($this->adminToken, $ids),
        );
        $this->assertSame(
            [$ownToday->id => false, $ownYesterday->id => false, $othersToday->id => false, $ownVoided->id => false],
            $this->mapCanVoid($this->createRegistrarToken(), $ids),
        );
    }

    public function test_void_lowers_total_paid_and_collection_total(): void
    {
        $this->pay($this->cashierToken, '1000.00');
        $secondPaymentId = $this->pay($this->cashierToken, '2000.00');
        $this->fetchPayments($this->cashierToken)->assertOk()->assertJsonPath('collection_total', '3000.00');

        $this->voidPayment($this->cashierToken, $secondPaymentId)->assertOk();

        $this->fetchPayments($this->cashierToken)->assertOk()->assertJsonPath('collection_total', '1000.00')->assertJsonPath('meta.total', 2);
        $this->sendAs($this->cashierToken, 'GET', self::ASSESSMENTS_URL.'/'.$this->assessment['id'])
            ->assertOk()
            ->assertJsonPath('assessment.total_paid', '1000.00')
            ->assertJsonPath('assessment.balance', '4000.00');
    }

    private function pay(string $token, string $amount = '1000.00'): int
    {
        return $this->recordPayment($token, $this->buildPaymentPayload($this->assessment['id'], ['amount' => $amount]))->assertCreated()->json('payment.id');
    }

    /**
     * @param  list<int>  $paymentIds
     * @return array<int, bool>
     */
    private function mapCanVoid(string $token, array $paymentIds): array
    {
        $rows = $this->fetchPayments($token)->assertOk()->json('payments');
        $canVoidById = collect($rows)->mapWithKeys(fn (array $row) => [$row['id'] => $row['can_void']])->all();

        return collect($paymentIds)->mapWithKeys(fn (int $paymentId) => [$paymentId => $canVoidById[$paymentId]])->all();
    }

    private function travelToManila(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, self::MANILA));
    }
}
