<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\FeeItem;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class AssessmentAccessTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    private Assessment $assessment;

    private PaymentPlan $paymentPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->paymentPlan = $this->createCashPlan();
        $this->assessment = Assessment::factory()->create(['school_year_id' => $this->schoolYear->id, 'payment_plan_id' => $this->paymentPlan->id]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAssessmentRoutes(): array
    {
        return [
            'list' => ['GET', self::ASSESSMENTS_URL.'?school_year_id={schoolYear}'],
            'candidates' => ['GET', self::ASSESSMENTS_URL.'/candidates?school_year_id={schoolYear}'],
            'preview' => ['POST', self::ASSESSMENTS_URL.'/preview'],
            'create' => ['POST', self::ASSESSMENTS_URL],
            'show' => ['GET', self::ASSESSMENTS_URL.'/{assessment}'],
            'history' => ['GET', self::ASSESSMENTS_URL.'/{assessment}/history'],
            'reassess' => ['POST', self::ASSESSMENTS_URL.'/{assessment}/reassess'],
        ];
    }

    #[DataProvider('provideAssessmentRoutes')]
    public function test_guest_gets_401_on_assessment_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotAssessmentTables();

        $this->json($method, $this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    #[DataProvider('provideAssessmentRoutes')]
    public function test_user_without_staff_role_gets_403_on_assessment_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotAssessmentTables();

        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->json($method, $this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotAssessmentTables());
    }

    public function test_admin_can_use_every_assessment_endpoint(): void
    {
        $this->assertCanUseEveryAssessmentEndpoint($this->adminToken);
    }

    public function test_registrar_can_use_every_assessment_endpoint(): void
    {
        $this->assertCanUseEveryAssessmentEndpoint($this->createRegistrarToken());
    }

    public function test_cashier_can_read_assessments(): void
    {
        $cashierToken = $this->createCashierToken();
        $baseUrl = self::ASSESSMENTS_URL;

        $this->withToken($cashierToken)->getJson($baseUrl.'?school_year_id='.$this->schoolYear->id)->assertOk();
        $this->withToken($cashierToken)->getJson($baseUrl.'/'.$this->assessment->id)->assertOk()->assertJsonPath('assessment.id', $this->assessment->id);
        $this->withToken($cashierToken)->getJson($baseUrl.'/'.$this->assessment->id.'/history')->assertOk();
    }

    private function assertCanUseEveryAssessmentEndpoint(string $token): void
    {
        $this->createJhsSchedule([[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '5000.00']]);
        $payload = $this->buildAssessmentPayload($this->createEnrolledStudent(GradeLevel::STAGE_JHS), $this->paymentPlan);
        $baseUrl = self::ASSESSMENTS_URL;

        $this->withToken($token)->postJson($baseUrl.'/preview', $payload)->assertOk();
        $createdId = $this->withToken($token)->postJson($baseUrl, $payload)->assertCreated()->json('assessment.id');
        $this->withToken($token)->getJson($baseUrl.'?school_year_id='.$this->schoolYear->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->withToken($token)->getJson($baseUrl.'/candidates?school_year_id='.$this->schoolYear->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson($baseUrl.'/'.$createdId)->assertOk()->assertJsonPath('assessment.id', $createdId);
        $this->withToken($token)->postJson($baseUrl.'/'.$createdId.'/reassess', ['payment_plan_id' => $this->paymentPlan->id])->assertCreated();
        $this->withToken($token)->getJson($baseUrl.'/'.$createdId.'/history')->assertOk()->assertJsonCount(2, 'assessments');
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace(['{schoolYear}', '{assessment}'], [$this->schoolYear->id, $this->assessment->id], $uriPattern);
    }

    /**
     * @return array<string, int>
     */
    private function buildRoutePayload(): array
    {
        return [
            'student_id' => $this->assessment->student_id,
            'school_year_id' => $this->schoolYear->id,
            'payment_plan_id' => $this->paymentPlan->id,
        ];
    }
}
