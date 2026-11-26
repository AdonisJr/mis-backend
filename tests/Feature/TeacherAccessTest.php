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

    // routes a teacher reaches besides the session ones: the MIS-24 reads, then the MIS-25 grading routes (class writes are scoped to the class teacher)
    private const TEACHER_ROUTES = [
        'GET api/mis/school-years',
        'GET api/mis/school-years/get-active',
        'GET api/mis/school-years/{id}/terms',
        'GET api/mis/terms/get-active',
        'GET api/mis/grade-levels',
        'GET api/mis/my-classes',
        'GET api/mis/my-advisory-section',
        'GET api/mis/classes/{id}/roster',
        'GET api/mis/classes/{id}/quarters/{quarter}/record',
        'GET api/mis/classes/{id}/quarters/{quarter}/activities',
        'POST api/mis/classes/{id}/quarters/{quarter}/activities',
        'PUT api/mis/classes/{id}/quarters/{quarter}/activities/{activityId}',
        'DELETE api/mis/classes/{id}/quarters/{quarter}/activities/{activityId}',
        'PUT api/mis/classes/{id}/quarters/{quarter}/scores',
        'POST api/mis/classes/{id}/quarters/{quarter}/submit',
        'GET api/mis/students/{id}/report-card',
        'GET api/mis/sections/{id}/report-cards',
    ];

    private const PLACEHOLDERS = ['{id}', '{quarter}', '{activityId}'];

    // every api/mis route except the session ones and the teacher reads; a new route that teacher can reach fails the 403 loop first
    private const CHECKED_ROUTE_COUNT = 131;

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
            ->reject(fn ($route) => in_array($route->uri(), self::SESSION_URIS, true))
            ->reject(fn ($route) => $this->isTeacherRoute($route));

        foreach ($checkedRoutes as $route) {
            $this->withToken($teacherToken)
                ->json($route->methods()[0], '/'.str_replace(self::PLACEHOLDERS, '1', $route->uri()))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertCount(self::CHECKED_ROUTE_COUNT, $checkedRoutes);
    }

    public function test_teacher_can_read_school_year_and_term_pickers(): void
    {
        $teacherToken = $this->createTeacherToken();

        $this->withToken($teacherToken)->getJson('/api/mis/school-years')->assertOk();
        $this->withToken($teacherToken)->getJson('/api/mis/school-years/get-active')->assertOk();
        $this->withToken($teacherToken)->getJson('/api/mis/school-years/'.$this->schoolYear->id.'/terms')->assertOk();
        $this->withToken($teacherToken)->getJson('/api/mis/terms/get-active')->assertOk();
    }

    public function test_teacher_reachable_routes_match_the_list(): void
    {
        // the school-year list URI is shared with its POST create route, so reachability is read from the role middleware
        $teacherRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn ($route) => in_array($route->uri(), self::SESSION_URIS, true))
            ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && str_ends_with($middleware, ','.Role::TEACHER)));

        $this->assertCount(count(self::TEACHER_ROUTES), $teacherRoutes);

        foreach ($teacherRoutes as $route) {
            $this->assertContains($route->methods()[0].' '.$route->uri(), self::TEACHER_ROUTES);
        }
    }

    public function test_teacher_with_registrar_role_keeps_registrar_access(): void
    {
        $teacherRegistrar = User::factory()->registrar()->teacher()->create();
        $mixedToken = $this->createTokenFor($teacherRegistrar);

        $this->withToken($mixedToken)->getJson(self::TEACHERS_URL)->assertOk();
        $this->withToken($mixedToken)->getJson(self::ROOMS_URL)->assertOk();
        $this->withToken($mixedToken)->getJson('/api/mis/employees')->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    private function isTeacherRoute($route): bool
    {
        return in_array($route->methods()[0].' '.$route->uri(), self::TEACHER_ROUTES, true);
    }
}
