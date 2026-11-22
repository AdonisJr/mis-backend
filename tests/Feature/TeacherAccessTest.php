<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class TeacherAccessTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const LOGIN_URL = '/api/mis/login';

    private const USER_URL = '/api/mis/user';

    private const LOGOUT_URL = '/api/mis/logout';

    private const TEACHER_PASSWORD = 'secret-pass-1';

    private const SESSION_URIS = ['api/mis/login', 'api/mis/user', 'api/mis/logout'];

    // every api/mis route except the three session ones; a new route that teacher can reach fails the 403 loop first
    private const CHECKED_ROUTE_COUNT = 116;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_teacher_can_log_in_fetch_user_and_log_out(): void
    {
        $this->createTeacher(['email' => 'teacher@school.test', 'password' => self::TEACHER_PASSWORD]);

        $loginResponse = $this->postJson(self::LOGIN_URL, ['email' => 'teacher@school.test', 'password' => self::TEACHER_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.roles', [Role::TEACHER]);
        $teacherToken = $loginResponse->json('token');

        $this->withToken($teacherToken)->getJson(self::USER_URL)
            ->assertOk()
            ->assertJsonPath('user.email', 'teacher@school.test')
            ->assertJsonPath('user.roles', [Role::TEACHER]);
        $this->withToken($teacherToken)->postJson(self::LOGOUT_URL)->assertNoContent();
        $this->assertSame(0, User::where('email', 'teacher@school.test')->firstOrFail()->tokens()->count());
    }

    public function test_teacher_gets_403_on_every_other_mis_route(): void
    {
        $teacherToken = $this->createTeacherToken();
        $checkedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn ($route) => in_array($route->uri(), self::SESSION_URIS, true));

        foreach ($checkedRoutes as $route) {
            $this->withToken($teacherToken)
                ->json($route->methods()[0], '/'.str_replace('{id}', '1', $route->uri()))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertCount(self::CHECKED_ROUTE_COUNT, $checkedRoutes);
    }

    public function test_teacher_with_registrar_role_keeps_registrar_access(): void
    {
        $teacherRegistrar = User::factory()->registrar()->teacher()->create();
        $mixedToken = $this->createTokenFor($teacherRegistrar);

        $this->withToken($mixedToken)->getJson(self::TEACHERS_URL)->assertOk();
        $this->withToken($mixedToken)->getJson(self::ROOMS_URL)->assertOk();
        $this->withToken($mixedToken)->getJson('/api/mis/employees')->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }
}
