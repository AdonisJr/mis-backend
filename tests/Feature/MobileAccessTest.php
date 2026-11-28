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

    // every api/mis route except the two public logins and the six mobile routes
    private const CHECKED_ROUTE_COUNT = 174;

    private const MOBILE_ROUTES = [
        'GET api/mis/mobile/me',
        'PUT api/mis/mobile/password',
        'POST api/mis/mobile/logout',
        'GET api/mis/mobile/grades',
        'GET api/mis/mobile/attendance',
        'GET api/mis/mobile/children',
    ];

    private const GATED_ROUTES = ['GET api/mis/mobile/grades', 'GET api/mis/mobile/attendance', 'GET api/mis/mobile/children'];

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

    private function assertForbiddenOnEveryOtherMisRoute(string $mobileToken): void
    {
        $checkedRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/mis/'))
            ->reject(fn (RoutingRoute $route) => in_array($route->uri(), self::PUBLIC_URIS, true) || str_starts_with($route->uri(), self::MOBILE_PREFIX));

        foreach ($checkedRoutes as $route) {
            $this->withToken($mobileToken)
                ->json($route->methods()[0], '/'.str_replace(['{id}', '{quarter}', '{activityId}', '{parentId}'], '1', $route->uri()))
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }

        $this->assertCount(self::CHECKED_ROUTE_COUNT, $checkedRoutes);
    }

    public function test_student_token_gets_403_on_every_other_mis_route(): void
    {
        $this->assertForbiddenOnEveryOtherMisRoute($this->createTokenFor(User::factory()->studentAccount()->create()));
    }

    public function test_parent_token_gets_403_on_every_other_mis_route(): void
    {
        $this->assertForbiddenOnEveryOtherMisRoute($this->createTokenFor(User::factory()->parentAccount()->create()));
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

        $this->assertCount(6, $mobileRoutes);
    }

    public function test_role_less_user_gets_403_on_the_mobile_group_routes(): void
    {
        $roleLessToken = $this->createRoleLessToken();

        foreach ($this->listMobileGroupRoutes() as $route) {
            $this->withToken($roleLessToken)->json($route->methods()[0], '/'.$route->uri())->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        }
    }

    public function test_only_the_six_mobile_routes_carry_the_mobile_roles_and_only_the_reads_the_gate(): void
    {
        $mobileRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => collect($route->gatherMiddleware())->contains('role:'.implode(',', Role::MOBILE_ROLES)));

        $this->assertEqualsCanonicalizing(self::MOBILE_ROUTES, $mobileRoutes->map(fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri())->all());

        foreach ($mobileRoutes as $route) {
            $isGated = in_array('password.changed', $route->gatherMiddleware(), true);
            $this->assertSame(in_array($route->methods()[0].' '.$route->uri(), self::GATED_ROUTES, true), $isGated);
        }

        $parentRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => collect($route->gatherMiddleware())->contains('role:'.Role::PARENT));

        $this->assertSame(['GET api/mis/mobile/children'], $parentRoutes->map(fn (RoutingRoute $route) => $route->methods()[0].' '.$route->uri())->values()->all());
    }
}
