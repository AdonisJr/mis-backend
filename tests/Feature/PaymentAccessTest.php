<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class PaymentAccessTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $assessment;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->assessment = $this->assessForPayments('5000.00', ['2030-08-15', '2030-09-15']);
        $this->payment = Payment::factory()->create(['assessment_id' => $this->assessment['id']]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providePaymentRoutes(): array
    {
        return [
            'list' => ['GET', self::PAYMENTS_URL],
            'ledger' => ['GET', self::PAYMENTS_URL.'/ledger?student_id={student}&school_year_id={schoolYear}'],
            'recorders' => ['GET', self::PAYMENTS_URL.'/recorders'],
            'receipt' => ['GET', self::PAYMENTS_URL.'/{payment}/receipt'],
            'record' => ['POST', self::PAYMENTS_URL],
            'void' => ['PUT', self::PAYMENTS_URL.'/{payment}/void'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providePaymentWriteRoutes(): array
    {
        return [
            'record' => ['POST', self::PAYMENTS_URL],
            'void' => ['PUT', self::PAYMENTS_URL.'/{payment}/void'],
        ];
    }

    #[DataProvider('providePaymentRoutes')]
    public function test_guest_gets_401_on_payment_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotPaymentTables();
        // setUp assessed through the endpoint with a token, and the header and guard outlive that request
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->json($method, $this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    #[DataProvider('providePaymentRoutes')]
    public function test_user_without_staff_role_gets_403_on_payment_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->sendAs($this->createTokenFor(User::factory()->create()), $method, $this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    #[DataProvider('providePaymentWriteRoutes')]
    public function test_registrar_gets_403_on_payment_writes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotPaymentTables();

        $this->sendAs($this->createRegistrarToken(), $method, $this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotPaymentTables());
    }

    public function test_registrar_can_read_payments(): void
    {
        $registrarToken = $this->createRegistrarToken();

        $this->sendAs($registrarToken, 'GET', self::PAYMENTS_URL)->assertOk()->assertJsonPath('meta.total', 1);
        $this->fetchLedger($registrarToken, $this->assessment)->assertOk();
        $this->sendAs($registrarToken, 'GET', self::PAYMENTS_URL.'/recorders')->assertOk();
        $this->sendAs($registrarToken, 'GET', self::PAYMENTS_URL.'/'.$this->payment->id.'/receipt')->assertOk()->assertJsonPath('receipt.id', $this->payment->id);
    }

    public function test_cashier_can_use_every_payment_endpoint(): void
    {
        $this->assertCanUseEveryPaymentEndpoint($this->createCashierToken());
    }

    public function test_admin_can_use_every_payment_endpoint(): void
    {
        $this->assertCanUseEveryPaymentEndpoint($this->adminToken);
    }

    private function assertCanUseEveryPaymentEndpoint(string $token): void
    {
        $recordedId = $this->recordPayment($token, $this->buildPaymentPayload($this->assessment['id']))->assertCreated()->json('payment.id');

        $this->sendAs($token, 'GET', self::PAYMENTS_URL)->assertOk()->assertJsonPath('meta.total', 2);
        $this->fetchLedger($token, $this->assessment)->assertOk()->assertJsonPath('ledger.assessment_id', $this->assessment['id']);
        $this->sendAs($token, 'GET', self::PAYMENTS_URL.'/recorders')->assertOk();
        $this->sendAs($token, 'GET', self::PAYMENTS_URL.'/'.$recordedId.'/receipt')->assertOk()->assertJsonPath('receipt.id', $recordedId);
        $this->voidPayment($token, $recordedId)->assertOk()->assertJsonPath('payment.status', Payment::STATUS_VOIDED);
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace(
            ['{student}', '{schoolYear}', '{payment}'],
            [$this->assessment['student_id'], $this->schoolYear->id, $this->payment->id],
            $uriPattern,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRoutePayload(): array
    {
        return [...$this->buildPaymentPayload($this->assessment['id']), 'void_reason' => 'Test reason'];
    }
}
