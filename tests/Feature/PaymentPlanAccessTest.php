<?php

namespace Tests\Feature;

use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentPlans;
use Tests\TestCase;

class PaymentPlanAccessTest extends TestCase
{
    use InteractsWithPaymentPlans;
    use RefreshDatabase;

    private PaymentPlan $paymentPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->paymentPlan = $this->createMonthlyPlan();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providePaymentPlanRoutes(): array
    {
        return [
            'list' => ['GET', self::PAYMENT_PLANS_URL.'?school_year_id={schoolYear}'],
            'show' => ['GET', self::PAYMENT_PLANS_URL.'/{paymentPlan}'],
            'create' => ['POST', self::PAYMENT_PLANS_URL],
            'update' => ['PUT', self::PAYMENT_PLANS_URL.'/{paymentPlan}'],
            'deactivate' => ['PUT', self::PAYMENT_PLANS_URL.'/{paymentPlan}/deactivate'],
            'reactivate' => ['PUT', self::PAYMENT_PLANS_URL.'/{paymentPlan}/reactivate'],
            'delete' => ['DELETE', self::PAYMENT_PLANS_URL.'/{paymentPlan}'],
            'copy' => ['POST', self::PAYMENT_PLANS_URL.'/copy'],
        ];
    }

    public function test_admin_can_use_every_payment_plan_endpoint(): void
    {
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $planUrl = self::PAYMENT_PLANS_URL.'/'.$this->paymentPlan->id;

        $createdPlanId = $this->postPlan($this->buildPlanPayload(['name' => self::QUARTERLY_PLAN]))->assertCreated()->json('payment_plan.id');
        $this->withToken($this->adminToken)->getJson(self::PAYMENT_PLANS_URL.'?school_year_id='.$this->schoolYear->id)->assertOk()->assertJsonCount(2, 'payment_plans');
        $this->withToken($this->adminToken)->getJson($planUrl)->assertOk()->assertJsonPath('payment_plan.id', $this->paymentPlan->id);
        $this->putPlan($this->paymentPlan->id, $this->buildPlanPayload(['name' => 'Renamed']))->assertOk()->assertJsonPath('payment_plan.name', 'Renamed');
        $this->withToken($this->adminToken)->putJson($planUrl.'/deactivate')->assertOk()->assertJsonPath('payment_plan.is_active', false);
        $this->withToken($this->adminToken)->putJson($planUrl.'/reactivate')->assertOk()->assertJsonPath('payment_plan.is_active', true);
        $this->withToken($this->adminToken)
            ->postJson(self::PAYMENT_PLANS_URL.'/copy', ['source_school_year_id' => $this->schoolYear->id, 'target_school_year_id' => $targetYear->id])
            ->assertOk()
            ->assertJsonPath('copied_count', 2);
        $this->withToken($this->adminToken)->deleteJson(self::PAYMENT_PLANS_URL.'/'.$createdPlanId)->assertNoContent();
        $this->withToken($this->adminToken)->deleteJson($planUrl)->assertNoContent();
    }

    #[DataProvider('providePaymentPlanRoutes')]
    public function test_guest_gets_401_on_payment_plan_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotPlanTables();

        $this->json($method, $this->buildTargetUri($uriPattern), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
    }

    #[DataProvider('providePaymentPlanRoutes')]
    public function test_registrar_gets_403_on_payment_plan_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotPlanTables();

        $this->withToken($this->createRegistrarToken())
            ->json($method, $this->buildTargetUri($uriPattern), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotPlanTables());
        $this->assertTrue($this->paymentPlan->fresh()->is_active);
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace(['{schoolYear}', '{paymentPlan}'], [$this->schoolYear->id, $this->paymentPlan->id], $uriPattern);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        $targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        return [
            ...$this->buildPlanPayload(['name' => 'Changed']),
            'source_school_year_id' => $this->schoolYear->id,
            'target_school_year_id' => $targetYear->id,
        ];
    }
}
