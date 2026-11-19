<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\Section;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPayments;
use Tests\TestCase;

class CashierAccessTest extends TestCase
{
    use InteractsWithPayments;
    use RefreshDatabase;

    private const MIS_URL = '/api/mis';

    private Student $student;

    private Section $section;

    private Enrollment $enrollment;

    private PaymentPlan $paymentPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAssessmentFixtures();
        $this->student = $this->createEnrolledStudent(GradeLevel::STAGE_JHS);
        $this->enrollment = Enrollment::firstOrFail();
        $this->section = Section::findOrFail($this->enrollment->section_id);
        $this->paymentPlan = $this->createCashPlan();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRegistryRoutes(): array
    {
        return [
            'students list' => ['GET', '/students'],
            'students create' => ['POST', '/students'],
            'students update' => ['PUT', '/students/{student}'],
            'students delete' => ['DELETE', '/students/{student}'],
            'sections list' => ['GET', '/sections'],
            'sections rollover preview' => ['GET', '/sections/{section}/rollover-preview'],
            'sections rollover' => ['POST', '/sections/{section}/rollover'],
            'sections complete' => ['PUT', '/sections/{section}/complete'],
            'enrollments list' => ['GET', '/enrollments'],
            'enrollments enroll' => ['POST', '/enrollments'],
            'enrollments move' => ['PUT', '/enrollments/{enrollment}/move'],
            'enrollments drop' => ['PUT', '/enrollments/{enrollment}/drop'],
            'enrollments transfer' => ['PUT', '/enrollments/{enrollment}/transfer'],
            'enrollments complete' => ['PUT', '/enrollments/{enrollment}/complete'],
            'assessment candidates' => ['GET', '/assessments/candidates'],
            'assessment preview' => ['POST', '/assessments/preview'],
            'assessment create' => ['POST', '/assessments'],
            'assessment reassess' => ['POST', '/assessments/{enrollment}/reassess'],
            'payment plans list' => ['GET', '/payment-plans'],
            'payment plans show' => ['GET', '/payment-plans/{paymentPlan}'],
        ];
    }

    #[DataProvider('provideRegistryRoutes')]
    public function test_cashier_gets_403_on_registry_routes(string $method, string $uriPattern): void
    {
        $snapshot = $this->snapshotRegistryTables();

        $this->sendAs($this->createCashierToken(), $method, self::MIS_URL.$this->buildTargetUri($uriPattern), $this->buildRoutePayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEquals($snapshot, $this->snapshotRegistryTables());
    }

    public function test_cashier_can_use_any_staff_routes(): void
    {
        $cashierToken = $this->createCashierToken();
        $baseUrl = self::MIS_URL;

        $this->sendAs($cashierToken, 'GET', $baseUrl.'/user')->assertOk()->assertJsonPath('user.roles', ['cashier']);
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/school-years/get-active')->assertOk();
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/terms/get-active')->assertOk();
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/school-years')->assertOk();
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/school-years/'.$this->schoolYear->id.'/terms')->assertOk();
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/grade-levels')->assertOk();
        $this->sendAs($cashierToken, 'GET', $baseUrl.'/programs')->assertOk();
        $this->sendAs($cashierToken, 'POST', $baseUrl.'/logout')->assertNoContent();
    }

    private function buildTargetUri(string $uriPattern): string
    {
        return str_replace(
            ['{student}', '{section}', '{enrollment}', '{paymentPlan}'],
            [$this->student->id, $this->section->id, $this->enrollment->id, $this->paymentPlan->id],
            $uriPattern,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRoutePayload(): array
    {
        return [
            'student_id' => $this->student->id,
            'school_year_id' => $this->schoolYear->id,
            'payment_plan_id' => $this->paymentPlan->id,
        ];
    }

    /**
     * @return list<array<int, object>>
     */
    private function snapshotRegistryTables(): array
    {
        return array_map(
            fn (string $table) => DB::table($table)->orderBy('id')->get()->all(),
            ['students', 'sections', 'enrollments', 'assessments', 'payment_plans'],
        );
    }
}
