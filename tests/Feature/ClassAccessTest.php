<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithClasses;
use Tests\TestCase;

class ClassAccessTest extends TestCase
{
    use InteractsWithClasses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideClassRoutes(): array
    {
        return [
            'show class' => ['GET', self::CLASSES_URL.'/1'],
            'create class' => ['POST', self::CLASSES_URL],
            'update class' => ['PUT', self::CLASSES_URL.'/1'],
            'delete class' => ['DELETE', self::CLASSES_URL.'/1'],
            'section timetable' => ['GET', '/api/mis/sections/1/timetable'],
            'teacher timetable' => ['GET', '/api/mis/teachers/1/timetable'],
            'room timetable' => ['GET', '/api/mis/rooms/1/timetable'],
        ];
    }

    public function test_admin_uses_every_class_route(): void
    {
        $this->exerciseEveryRoute($this->adminToken);
    }

    public function test_registrar_uses_every_class_route(): void
    {
        $this->exerciseEveryRoute($this->createRegistrarToken());
    }

    #[DataProvider('provideClassRoutes')]
    public function test_cashier_gets_403(string $method, string $url): void
    {
        $this->assertForbiddenFor($this->createCashierToken(), $method, $url);
    }

    #[DataProvider('provideClassRoutes')]
    public function test_teacher_gets_403(string $method, string $url): void
    {
        $this->assertForbiddenFor($this->createTeacherToken(), $method, $url);
    }

    #[DataProvider('provideClassRoutes')]
    public function test_role_less_user_gets_403(string $method, string $url): void
    {
        $this->assertForbiddenFor($this->createRoleLessToken(), $method, $url);
    }

    #[DataProvider('provideClassRoutes')]
    public function test_guest_gets_401(string $method, string $url): void
    {
        $this->json($method, $url, $this->buildClassPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertDatabaseCount('classes', 0);
    }

    private function assertForbiddenFor(string $token, string $method, string $url): void
    {
        $this->withToken($token)->json($method, $url, $this->buildClassPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertDatabaseCount('classes', 0);
    }

    private function exerciseEveryRoute(string $token): void
    {
        $room = $this->createRoom(self::LAB_A);
        $payload = $this->buildClassPayload(['meetings' => [$this->buildMeeting(['room_id' => $room->id])]]);

        $classId = $this->withToken($token)->postJson(self::CLASSES_URL, $payload)->assertCreated()->json('class.id');
        $classUrl = self::CLASSES_URL.'/'.$classId;
        $schoolYearQuery = '?school_year_id='.$this->schoolYear->id;

        $this->withToken($token)->getJson($classUrl)->assertOk();
        $this->withToken($token)->putJson($classUrl, ['teacher_id' => $this->mathTeacher->id, 'meetings' => []])->assertOk();
        $this->withToken($token)->getJson(self::SECTIONS_URL.'/'.$this->rizalSection->id.'/timetable')->assertOk();
        $this->withToken($token)->getJson(self::TEACHERS_URL.'/'.$this->mathTeacher->id.'/timetable'.$schoolYearQuery)->assertOk();
        $this->withToken($token)->getJson(self::ROOMS_URL.'/'.$room->id.'/timetable'.$schoolYearQuery)->assertOk();
        $this->withToken($token)->deleteJson($classUrl)->assertNoContent();
    }
}
