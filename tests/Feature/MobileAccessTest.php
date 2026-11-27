<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class MobileAccessTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PUBLIC_URIS = ['api/mis/login', 'api/mis/mobile/login'];

    private const MOBILE_PREFIX = 'api/mis/mobile/';

    // every api/mis route except the two public logins and the four mobile routes
    private const CHECKED_ROUTE_COUNT = 160;

    private const MOBILE_ROUTES = [
        'GET api/mis/mobile/me',
        'PUT api/mis/mobile/password',
        'POST api/mis/mobile/logout',
        'GET api/mis/mobile/grades',
    ];

    private const GATED_ROUTE = 'GET api/mis/mobile/grades';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return list<RoutingRoute>
     */
    private function listMobileGroupRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), self::MOBILE_PREFIX) && $route->uri() !== 'api/mis/mobile/login')
            ->values()
            ->all();
    }

    public function test_student_token_gets_403_on_every_other_mis_route(): void
    {
        $studentToken = $this->createTokenFor(User::factory()->studentAccount()->create());
        $checkedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn (RoutingRoute $route) => in_array($route->uri(), self::PUBLIC_URIS, true) || str_starts_with($route->uri(), self::MOBILE_PREFIX));

        foreach ($checkedRoutes as $route) {
            $this->withToken($studentToken)
                ->json($route->methods()[0], '/'.str_replace(['{id}', '{quarter}', '{activityId}'], '1', $route->uri()))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertCount(self::CHECKED_ROUTE_COUNT, $checkedRoutes);
    }

    public function test_every_staff_role_gets_403_on_the_mobile_group_routes(): void
    {
        $mobileRoutes = $this->listMobileGroupRoutes();

        foreach (Role::EMPLOYEE_ROLES as $roleName) {
            $staffUser = User::factory()->create(['email' => $roleName.'@school.test']);
            $staffUser->roles()->attach(Role::firstOrCreate(['name' => $roleName]));
            $roleToken = $this->createTokenFor($staffUser);

            foreach ($mobileRoutes as $route) {
                $this->withToken($roleToken)->json($route->methods()[0], '/'.$route->uri())->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
            }
        }

        $this->assertCount(4, $mobileRoutes);
    }

    public function test_role_less_user_gets_403_on_the_mobile_group_routes(): void
    {
        $roleLessToken = $this->createRoleLessToken();

        foreach ($this->listMobileGroupRoutes() as $route) {
            $this->withToken($roleLessToken)->json($route->methods()[0], '/'.$route->uri())->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        }
    }

    public function test_only_the_four_mobile_routes_carry_the_student_role_and_only_grades_the_gate(): void
    {
        $studentRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => collect($route->gatherMiddleware())->contains('role:'.Role::STUDENT));

        $this->assertEqualsCanonicalizing(self::MOBILE_ROUTES, $studentRoutes->map(fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri())->all());

        foreach ($studentRoutes as $route) {
            $isGated = in_array('password.changed', $route->gatherMiddleware(), true);
            $this->assertSame($route->methods()[0].' '.$route->uri() === self::GATED_ROUTE, $isGated);
        }
    }
}
