<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const PASSWORD = 'secret-pass-1';

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const VALID_PAYLOAD = [
        'name' => 'Maria Santos',
        'email' => 'maria.santos@school.test',
        'contact' => '09171234567',
        'password' => self::PASSWORD,
        'password_confirmation' => self::PASSWORD,
        'roles' => [Role::REGISTRAR],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Role::STAFF_ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEmployeeRoutes(): array
    {
        return [
            'list' => ['GET', self::EMPLOYEES_URL],
            'create' => ['POST', self::EMPLOYEES_URL],
            'update' => ['PUT', self::EMPLOYEES_URL.'/%d'],
            'password' => ['PUT', self::EMPLOYEES_URL.'/%d/password'],
            'deactivate' => ['PUT', self::EMPLOYEES_URL.'/%d/deactivate'],
            'reactivate' => ['PUT', self::EMPLOYEES_URL.'/%d/reactivate'],
        ];
    }

    public function test_admin_can_use_every_employee_endpoint(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());

        $this->withToken($adminToken)->getJson(self::EMPLOYEES_URL)->assertOk();

        $createdEmployeeId = $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->json('employee.id');
        $employeeUrl = self::EMPLOYEES_URL.'/'.$createdEmployeeId;

        $this->withToken($adminToken)
            ->putJson($employeeUrl, [...self::VALID_PAYLOAD, 'name' => 'Maria Santos-Reyes'])
            ->assertOk()
            ->assertJsonPath('employee.name', 'Maria Santos-Reyes');
        $this->withToken($adminToken)
            ->putJson($employeeUrl.'/password', ['password' => 'new-secret-2', 'password_confirmation' => 'new-secret-2'])
            ->assertOk();
        $this->withToken($adminToken)
            ->putJson($employeeUrl.'/deactivate')
            ->assertOk()
            ->assertJsonPath('employee.is_active', false);
        $this->withToken($adminToken)
            ->putJson($employeeUrl.'/reactivate')
            ->assertOk()
            ->assertJsonPath('employee.is_active', true);
    }

    #[DataProvider('provideEmployeeRoutes')]
    public function test_guest_gets_401_on_employee_routes(string $method, string $uriPattern): void
    {
        $targetEmployee = $this->createTargetEmployee();

        $this->json($method, sprintf($uriPattern, $targetEmployee->id), self::VALID_PAYLOAD)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetEmployee);
        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('provideEmployeeRoutes')]
    public function test_registrar_gets_403_on_employee_routes(string $method, string $uriPattern): void
    {
        $targetEmployee = $this->createTargetEmployee();
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->json($method, sprintf($uriPattern, $targetEmployee->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetEmployee);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('provideEmployeeRoutes')]
    public function test_user_without_role_gets_403_on_employee_routes(string $method, string $uriPattern): void
    {
        $targetEmployee = $this->createTargetEmployee();
        $roleLessUser = User::factory()->create(['email' => 'norole@school.test']);

        $this->withToken($this->createTokenFor($roleLessUser))
            ->json($method, sprintf($uriPattern, $targetEmployee->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetEmployee);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_registrar_gets_403_for_missing_id(): void
    {
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create(['email' => 'registrar@school.test']));

        foreach (['', '/password', '/deactivate'] as $routeSuffix) {
            $this->withToken($registrarToken)
                ->putJson(self::EMPLOYEES_URL.'/'.self::MISSING_ID.$routeSuffix, self::VALID_PAYLOAD)
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }
    }

    public function test_employee_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::EMPLOYEES_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    private function createTargetEmployee(): User
    {
        $targetEmployee = User::factory()->registrar()->create(['password' => self::PASSWORD]);
        $this->createTokenFor($targetEmployee);

        return $targetEmployee;
    }

    private function assertTargetUnchanged(User $targetEmployee): void
    {
        $reloadedEmployee = $targetEmployee->fresh();

        $this->assertSame($targetEmployee->name, $reloadedEmployee->name);
        $this->assertSame($targetEmployee->email, $reloadedEmployee->email);
        $this->assertTrue($reloadedEmployee->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $reloadedEmployee->password));
        $this->assertSame(1, $reloadedEmployee->tokens()->count());
        $this->assertSame([Role::REGISTRAR], $reloadedEmployee->roles->pluck('name')->all());
    }

    private function createActingAdmin(): User
    {
        return User::factory()->admin()->create(self::ACTING_ADMIN);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
