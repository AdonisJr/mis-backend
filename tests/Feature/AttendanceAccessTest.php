<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithAttendance;
use Tests\TestCase;

class AttendanceAccessTest extends TestCase
{
    use InteractsWithAttendance;
    use RefreshDatabase;

    private const RECORDS_QUERY = '?date_from=2030-09-01&date_to=2030-09-02';

    /**
     * @return array<string, array{string}>
     */
    public static function provideAdminUrls(): array
    {
        return [
            'today' => [self::TODAY_URL],
            'records' => [self::RECORDS_URL.self::RECORDS_QUERY],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideExcludedRoles(): array
    {
        return [
            'cashier' => [Role::CASHIER],
            'accountant' => [Role::ACCOUNTANT],
            'teacher' => [Role::TEACHER],
            'student' => [Role::STUDENT],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAllowedRoles(): array
    {
        return [
            'admin' => [Role::ADMIN],
            'registrar' => [Role::REGISTRAR],
        ];
    }

    #[DataProvider('provideAdminUrls')]
    public function test_guest_gets_401_on_the_admin_reads(string $url): void
    {
        $this->getJson($url)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    #[DataProvider('provideExcludedRoles')]
    public function test_excluded_role_gets_403_on_the_admin_reads(string $roleName): void
    {
        $roleToken = $this->createRoleToken($roleName);

        $this->withToken($roleToken)->getJson(self::TODAY_URL)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        $this->withToken($roleToken)->getJson(self::RECORDS_URL.self::RECORDS_QUERY)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_role_less_user_gets_403_on_the_admin_reads(): void
    {
        $roleLessToken = User::factory()->create()->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->withToken($roleLessToken)->getJson(self::TODAY_URL)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
    }

    #[DataProvider('provideAllowedRoles')]
    public function test_admin_and_registrar_can_read_attendance(string $roleName): void
    {
        $roleToken = $this->createRoleToken($roleName);

        $this->withToken($roleToken)->getJson(self::TODAY_URL)->assertOk();
        $this->withToken($roleToken)->getJson(self::RECORDS_URL.self::RECORDS_QUERY)->assertOk();
    }

    public function test_guest_gets_401_on_the_mobile_read(): void
    {
        $this->getJson(self::MOBILE_URL)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_every_employee_role_gets_403_on_the_mobile_read(): void
    {
        foreach (Role::EMPLOYEE_ROLES as $roleName) {
            $this->withToken($this->createRoleToken($roleName))->getJson(self::MOBILE_URL)->assertForbidden()->assertExactJson(self::FORBIDDEN_BODY);
        }
    }

    public function test_scan_route_is_public_and_carries_only_its_own_throttle(): void
    {
        $scanRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => $route->uri() === 'api/scan');

        $this->assertCount(1, $scanRoutes);
        $this->assertSame(['POST'], $scanRoutes->first()->methods());
        $this->assertSame(['api', 'throttle:60,1,scan'], $scanRoutes->first()->gatherMiddleware());
    }
}
