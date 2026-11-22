<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\GradingActivity;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithGrading;
use Tests\TestCase;

class GradingAccessTest extends TestCase
{
    use InteractsWithGrading;
    use RefreshDatabase;

    private const MISSING_CLASS_ID = 9999;

    private SchoolClass $schoolClass;

    private GradingActivity $quiz;

    private User $adviser;

    private Student $aquino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->adviser = $this->createTeacher(['email' => 'adviser@school.test']);
        $this->rizalSection->update(['adviser_id' => $this->adviser->id]);
        $this->schoolClass = $this->createJhsClass($this->mathTeacher);
        $this->quiz = $this->createActivity($this->schoolClass, ['title' => self::QUIZ_1]);
        $this->aquino = $this->enrollStudent(['last_name' => self::AQUINO], ['section_id' => $this->rizalSection->id])->student;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTeacherRoutes(): array
    {
        return [
            'record' => ['record'],
            'activity list' => ['activities'],
            'activity create' => ['create'],
            'activity update' => ['update'],
            'activity delete' => ['delete'],
            'score save' => ['scores'],
            'submit' => ['submit'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAllGradingUris(): array
    {
        $quarterUri = '/api/mis/classes/1/quarters/1';

        return [
            'record' => ['GET', $quarterUri.'/record'],
            'activity list' => ['GET', $quarterUri.'/activities'],
            'activity create' => ['POST', $quarterUri.'/activities'],
            'activity update' => ['PUT', $quarterUri.'/activities/1'],
            'activity delete' => ['DELETE', $quarterUri.'/activities/1'],
            'score save' => ['PUT', $quarterUri.'/scores'],
            'submit' => ['POST', $quarterUri.'/submit'],
            'reopen' => ['POST', $quarterUri.'/reopen'],
            'student report card' => ['GET', '/api/mis/students/1/report-card?school_year_id=1'],
            'section report cards' => ['GET', '/api/mis/sections/1/report-cards'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRegistryRoles(): array
    {
        return ['admin' => ['admin'], 'registrar' => ['registrar']];
    }

    private function actWithToken(string $token): static
    {
        // the sanctum guard keeps the first user of a test, so a second caller needs a fresh guard
        $this->app->make('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function callRoute(string $routeKey, string $token, ?SchoolClass $schoolClass = null): TestResponse
    {
        $schoolClass ??= $this->schoolClass;
        $activitiesUrl = $this->activitiesUrl($schoolClass, 1);
        $client = $this->actWithToken($token);

        return match ($routeKey) {
            'record' => $client->getJson($this->recordUrl($schoolClass, 1)),
            'activities' => $client->getJson($activitiesUrl),
            'create' => $client->postJson($activitiesUrl, $this->buildActivityPayload()),
            'update' => $client->putJson($activitiesUrl.'/'.$this->quiz->id, $this->buildActivityPayload()),
            'delete' => $client->deleteJson($activitiesUrl.'/'.$this->quiz->id),
            'scores' => $client->putJson($this->scoresUrl($schoolClass, 1), $this->buildScoresPayload([['student_id' => $this->aquino->id, 'activity_id' => $this->quiz->id, 'score' => 5]])),
            'submit' => $client->postJson($this->submitUrl($schoolClass, 1)),
        };
    }

    private function isReadRoute(string $routeKey): bool
    {
        return in_array($routeKey, ['record', 'activities'], true);
    }

    #[DataProvider('provideTeacherRoutes')]
    public function test_other_teacher_gets_403_on_every_teacher_route(string $routeKey): void
    {
        $otherTeacherToken = $this->createTokenFor($this->createTeacher());
        $expectedBody = $this->isReadRoute($routeKey) ? self::READ_NOT_PERMITTED_BODY : self::WRITE_NOT_PERMITTED_BODY;

        $this->callRoute($routeKey, $otherTeacherToken)->assertForbidden()->assertExactJson($expectedBody);
    }

    public function test_former_teacher_write_returns_403(): void
    {
        $newTeacher = $this->createTeacher();
        $this->schoolClass->update(['teacher_id' => $newTeacher->id]);

        $this->callRoute('create', $this->createTokenFor($this->mathTeacher))->assertForbidden()->assertExactJson(self::WRITE_NOT_PERMITTED_BODY);
    }

    public function test_new_teacher_continues_the_record(): void
    {
        $this->createScore($this->quiz, $this->aquino, '12.0');
        $newTeacher = $this->createTeacher();
        $this->schoolClass->update(['teacher_id' => $newTeacher->id]);
        $newTeacherToken = $this->createTokenFor($newTeacher);

        $this->callRoute('record', $newTeacherToken)->assertOk()->assertJsonPath('class_record.students.0.scores.0.score', '12.0');
        $this->callRoute('create', $newTeacherToken)->assertCreated();
    }

    public function test_adviser_reads_the_record(): void
    {
        $this->callRoute('record', $this->createTokenFor($this->adviser))->assertOk();
    }

    public function test_adviser_score_write_returns_403(): void
    {
        $this->callRoute('scores', $this->createTokenFor($this->adviser))->assertForbidden()->assertExactJson(self::WRITE_NOT_PERMITTED_BODY);
    }

    public function test_adviser_reads_the_report_card_of_a_student_in_the_section(): void
    {
        $this->withToken($this->createTokenFor($this->adviser))->getJson($this->reportCardUrl($this->aquino, $this->schoolYear->id))->assertOk();
    }

    public function test_adviser_of_the_section_a_student_left_reads_the_report_card(): void
    {
        $newSection = $this->createSection(['name' => self::MABINI]);
        $leaver = $this->enrollStudent(['last_name' => self::RAMOS], ['section_id' => $this->rizalSection->id, 'status' => Enrollment::STATUS_MOVED])->student;
        $this->enrollExistingStudent($leaver, ['section_id' => $newSection->id]);

        $this->withToken($this->createTokenFor($this->adviser))->getJson($this->reportCardUrl($leaver, $this->schoolYear->id))->assertOk();
    }

    public function test_adviser_of_another_section_gets_403_on_the_report_card(): void
    {
        $otherAdviser = $this->createTeacher();
        $this->createSection(['name' => self::MABINI, 'adviser_id' => $otherAdviser->id]);

        $this->withToken($this->createTokenFor($otherAdviser))->getJson($this->reportCardUrl($this->aquino, $this->schoolYear->id))
            ->assertForbidden()
            ->assertExactJson(self::REPORT_NOT_PERMITTED_BODY);
    }

    public function test_adviser_reads_the_section_report_cards(): void
    {
        $this->withToken($this->createTokenFor($this->adviser))->getJson($this->sectionReportCardsUrl($this->rizalSection))
            ->assertOk()
            ->assertJsonCount(1, 'report_cards');
    }

    public function test_other_teacher_gets_403_on_the_section_report_cards(): void
    {
        $this->withToken($this->createTokenFor($this->createTeacher()))->getJson($this->sectionReportCardsUrl($this->rizalSection))
            ->assertForbidden()
            ->assertExactJson(self::SECTION_CARDS_NOT_PERMITTED_BODY);
    }

    #[DataProvider('provideRegistryRoles')]
    public function test_registry_role_reads_the_record(string $role): void
    {
        $registryToken = $this->createTokenFor(User::factory()->{$role}()->create());

        $this->callRoute('record', $registryToken)->assertOk();
    }

    #[DataProvider('provideRegistryRoles')]
    public function test_registry_role_score_write_returns_403(string $role): void
    {
        $registryToken = $this->createTokenFor(User::factory()->{$role}()->create());

        $this->callRoute('scores', $registryToken)->assertForbidden()->assertExactJson(self::WRITE_NOT_PERMITTED_BODY);
    }

    public function test_registrar_can_reopen_a_submitted_quarter(): void
    {
        $this->createSubmittedQuarter($this->schoolClass, [['student_id' => $this->aquino->id]]);

        $this->withToken($this->createRegistrarToken())->postJson($this->reopenUrl($this->schoolClass, 1), ['reason' => 'Correction'])
            ->assertOk()
            ->assertJsonPath('class_record.status', 'draft');
    }

    public function test_teacher_reopen_returns_403(): void
    {
        $this->createSubmittedQuarter($this->schoolClass, [['student_id' => $this->aquino->id]]);

        $this->withToken($this->createTokenFor($this->mathTeacher))->postJson($this->reopenUrl($this->schoolClass, 1), ['reason' => 'Correction'])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideAllGradingUris')]
    public function test_cashier_gets_403(string $method, string $uri): void
    {
        $this->withToken($this->createCashierToken())->json($method, $uri)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideAllGradingUris')]
    public function test_guest_gets_401(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_missing_class_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::CLASSES_URL.'/'.self::MISSING_CLASS_ID.'/quarters/1/record')->assertNotFound();
    }

    public function test_other_teacher_on_a_missing_class_gets_404_before_any_scope_check(): void
    {
        $this->withToken($this->createTokenFor($this->createTeacher()))->postJson(self::CLASSES_URL.'/'.self::MISSING_CLASS_ID.'/quarters/1/activities', $this->buildActivityPayload())
            ->assertNotFound();
    }
}
