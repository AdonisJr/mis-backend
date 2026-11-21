<?php

namespace Tests\Feature;

use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class SubjectAccessTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideWriteRoutes(): array
    {
        return [
            'create' => ['POST', self::SUBJECTS_URL],
            'update' => ['PUT', self::SUBJECTS_URL.'/{subject}'],
            'deactivate' => ['PUT', self::SUBJECTS_URL.'/{subject}/deactivate'],
            'reactivate' => ['PUT', self::SUBJECTS_URL.'/{subject}/reactivate'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideReadRoutes(): array
    {
        return [
            'list' => ['GET', self::SUBJECTS_URL],
            'active list' => ['GET', self::ACTIVE_SUBJECTS_URL],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEveryRoute(): array
    {
        return [...self::provideReadRoutes(), ...self::provideWriteRoutes()];
    }

    public function test_admin_can_use_every_subject_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->adminToken);
    }

    public function test_registrar_can_use_every_subject_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createRegistrarToken());
    }

    #[DataProvider('provideReadRoutes')]
    public function test_cashier_can_read_subjects(string $method, string $uri): void
    {
        $this->withToken($this->createCashierToken())->json($method, $uri)->assertOk();
    }

    #[DataProvider('provideWriteRoutes')]
    public function test_cashier_gets_403_on_subject_writes(string $method, string $uriPattern): void
    {
        $targetSubject = $this->createSubject(self::MATH_7);

        $this->withToken($this->createCashierToken())
            ->json($method, strtr($uriPattern, ['{subject}' => $targetSubject->id]), $this->buildSubjectPayload(['code' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertUnchanged($targetSubject);
    }

    #[DataProvider('provideEveryRoute')]
    public function test_role_less_user_gets_403_on_subject_routes(string $method, string $uriPattern): void
    {
        $targetSubject = $this->createSubject(self::MATH_7);

        $this->withToken($this->createRoleLessToken())
            ->json($method, strtr($uriPattern, ['{subject}' => $targetSubject->id]), $this->buildSubjectPayload(['code' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertUnchanged($targetSubject);
    }

    #[DataProvider('provideEveryRoute')]
    public function test_guest_gets_401_on_subject_routes(string $method, string $uriPattern): void
    {
        $targetSubject = $this->createSubject(self::MATH_7);

        $this->json($method, strtr($uriPattern, ['{subject}' => $targetSubject->id]), $this->buildSubjectPayload(['code' => 'CHANGED']))
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertUnchanged($targetSubject);
    }

    private function assertEveryEndpointWorksFor(string $token): void
    {
        $createdSubjectId = $this->withToken($token)
            ->postJson(self::SUBJECTS_URL, $this->buildSubjectPayload())
            ->assertCreated()
            ->json('subject.id');
        $subjectUrl = self::SUBJECTS_URL.'/'.$createdSubjectId;

        $this->withToken($token)->getJson(self::SUBJECTS_URL)->assertOk()->assertJsonPath('subjects.0.code', self::MATH_7);
        $this->withToken($token)->getJson(self::ACTIVE_SUBJECTS_URL)->assertOk()->assertJsonPath('subjects.0.code', self::MATH_7);
        $this->withToken($token)->putJson($subjectUrl, $this->buildSubjectPayload(['name' => 'Renamed']))->assertOk()->assertJsonPath('subject.name', 'Renamed');
        $this->withToken($token)->putJson($subjectUrl.'/deactivate')->assertOk()->assertJsonPath('subject.is_active', false);
        $this->withToken($token)->putJson($subjectUrl.'/reactivate')->assertOk()->assertJsonPath('subject.is_active', true);
    }

    private function assertUnchanged(Subject $targetSubject): void
    {
        $reloadedSubject = $targetSubject->fresh();

        $this->assertSame(self::MATH_7, $reloadedSubject->code);
        $this->assertTrue($reloadedSubject->is_active);
        $this->assertDatabaseCount('subjects', 1);
    }
}
