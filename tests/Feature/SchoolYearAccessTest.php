<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchoolYearAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const SET_ACTIVE_URL = '/api/mis/school-years/set-active/';

    private const GET_ACTIVE_URL = '/api/mis/school-years/get-active';

    private const TOKEN_NAME = 'test';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const MISSING_ID = 999;

    private const VALID_PAYLOAD = [
        'name' => '2030-2031',
        'start_date' => '2030-08-01',
        'end_date' => '2031-05-31',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAdminRoutes(): array
    {
        return [
            'create' => ['POST', self::SCHOOL_YEARS_URL],
            'update' => ['PUT', self::SCHOOL_YEARS_URL.'/%d'],
            'delete' => ['DELETE', self::SCHOOL_YEARS_URL.'/%d'],
            'set-active' => ['PUT', self::SET_ACTIVE_URL.'%d'],
        ];
    }

    #[DataProvider('provideAdminRoutes')]
    public function test_guest_gets_401_on_admin_routes(string $method, string $uriPattern): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->json($method, sprintf($uriPattern, $schoolYear->id), self::VALID_PAYLOAD)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertDatabaseCount('school_years', 1);
        $this->assertSame($schoolYear->name, $schoolYear->fresh()->name);
        $this->assertFalse($schoolYear->fresh()->is_active);
    }

    #[DataProvider('provideAdminRoutes')]
    public function test_registrar_gets_403_on_admin_routes(string $method, string $uriPattern): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->json($method, sprintf($uriPattern, $schoolYear->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertDatabaseCount('school_years', 1);
        $this->assertSame($schoolYear->name, $schoolYear->fresh()->name);
        $this->assertFalse($schoolYear->fresh()->is_active);
    }

    #[DataProvider('provideAdminRoutes')]
    public function test_user_without_role_gets_403_on_admin_routes(string $method, string $uriPattern): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->json($method, sprintf($uriPattern, $schoolYear->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertDatabaseCount('school_years', 1);
        $this->assertSame($schoolYear->name, $schoolYear->fresh()->name);
        $this->assertFalse($schoolYear->fresh()->is_active);
    }

    public function test_registrar_gets_403_for_missing_id(): void
    {
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create());

        $this->withToken($registrarToken)
            ->deleteJson(self::SCHOOL_YEARS_URL.'/'.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->withToken($registrarToken)
            ->putJson(self::SET_ACTIVE_URL.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_registrar_lists_school_years(): void
    {
        SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk()
            ->assertJsonCount(1, 'school_years');
    }

    public function test_guest_gets_401_on_school_year_list(): void
    {
        $this->getJson(self::SCHOOL_YEARS_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_user_without_role_gets_403_on_school_year_list(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_guest_gets_401_on_get_active(): void
    {
        $this->getJson(self::GET_ACTIVE_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_user_without_role_gets_403_on_get_active(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->getJson(self::GET_ACTIVE_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_admin_route_without_accept_header_returns_401_json(): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->delete(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertNotNull($schoolYear->fresh());
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
