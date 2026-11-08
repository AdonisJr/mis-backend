<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeListTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const TOKEN_NAME = 'test';

    private const PAGE_SIZE = 15;

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const EMPLOYEE_KEYS = ['id', 'name', 'email', 'contact', 'is_active', 'roles', 'created_at', 'updated_at'];

    private const MARIA = ['name' => 'Maria Santos', 'email' => 'maria.santos@school.test', 'contact' => '09171234567'];

    private const JOSE = ['name' => 'Jose Rizal', 'email' => 'jrizal@school.test', 'contact' => null];

    public function test_list_returns_first_page_with_meta(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        User::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchEmployeeList($adminToken)
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'employees')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 17])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('links');
    }

    public function test_list_returns_second_page(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        User::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchEmployeeList($adminToken, ['page' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'employees')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_ignores_per_page_param(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        User::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchEmployeeList($adminToken, ['per_page' => 100])
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'employees')
            ->assertJsonPath('meta.per_page', self::PAGE_SIZE);
    }

    public function test_list_page_past_the_end_returns_empty_employees(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        User::factory()->count(self::PAGE_SIZE + 1)->create();

        $this->fetchEmployeeList($adminToken, ['page' => 999])
            ->assertOk()
            ->assertJsonPath('employees', [])
            ->assertJsonPath('meta', ['current_page' => 999, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 17]);
    }

    public function test_list_returns_exact_employee_shape(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);
        $mariaEmployee = User::factory()->registrar()->withContact()->create([
            'name' => self::MARIA['name'],
            'email' => self::MARIA['email'],
        ]);

        $listResponse = $this->fetchEmployeeList($adminToken)->assertOk();

        $listResponse->assertExactJson([
            'employees' => [
                $this->buildExpectedRow($mariaEmployee),
                $this->buildExpectedRow($actingAdmin),
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 2],
        ]);
        $this->assertSame(self::EMPLOYEE_KEYS, array_keys($listResponse->json('employees.0')));
        $this->assertStringNotContainsString('password', $listResponse->getContent());
        $this->assertStringNotContainsString('remember_token', $listResponse->getContent());
        $this->assertStringNotContainsString('email_verified_at', $listResponse->getContent());
    }

    public function test_list_rows_include_roles_contact_and_status(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $listedEmployees = collect($this->fetchEmployeeList($adminToken)->assertOk()->json('employees'));

        $mariaRow = $listedEmployees->firstWhere('email', self::MARIA['email']);
        $this->assertSame([Role::REGISTRAR], $mariaRow['roles']);
        $this->assertSame(self::MARIA['contact'], $mariaRow['contact']);
        $this->assertTrue($mariaRow['is_active']);

        $joseRow = $listedEmployees->firstWhere('email', self::JOSE['email']);
        $this->assertSame([Role::ADMIN, Role::REGISTRAR], $joseRow['roles']);
        $this->assertNull($joseRow['contact']);
        $this->assertFalse($joseRow['is_active']);
    }

    public function test_list_includes_role_less_users(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        User::factory()->create(['email' => 'norole@school.test']);

        $roleLessRow = collect($this->fetchEmployeeList($adminToken)->assertOk()->json('employees'))
            ->firstWhere('email', 'norole@school.test');

        $this->assertNotNull($roleLessRow);
        $this->assertSame([], $roleLessRow['roles']);
    }

    public function test_list_orders_newest_first(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $actingAdmin->created_at = now()->subDays(10);
        $actingAdmin->save();
        $adminToken = $this->createTokenFor($actingAdmin);
        $twoDaysOld = User::factory()->create(['created_at' => now()->subDays(2)]);
        $newest = User::factory()->create(['created_at' => now()]);
        $oneDayOld = User::factory()->create(['created_at' => now()->subDay()]);
        $sameSecondTimestamp = now()->subDays(3);
        $firstSameSecond = User::factory()->create(['created_at' => $sameSecondTimestamp]);
        $secondSameSecond = User::factory()->create(['created_at' => $sameSecondTimestamp]);

        $listedIds = array_column($this->fetchEmployeeList($adminToken)->assertOk()->json('employees'), 'id');

        $this->assertSame([
            $newest->id,
            $oneDayOld->id,
            $twoDaysOld->id,
            $secondSameSecond->id,
            $firstSameSecond->id,
            $actingAdmin->id,
        ], $listedIds);
    }

    public function test_search_by_name_is_case_insensitive(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame([self::MARIA['email']], $this->fetchListedEmails($adminToken, ['search' => 'maria']));
        $this->assertSame([self::MARIA['email']], $this->fetchListedEmails($adminToken, ['search' => 'SANTOS']));
    }

    public function test_search_by_email(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame([self::JOSE['email']], $this->fetchListedEmails($adminToken, ['search' => 'jrizal']));
        $this->assertSame(
            [self::JOSE['email'], self::MARIA['email']],
            $this->fetchListedEmails($adminToken, ['search' => 'school.test']),
        );
    }

    public function test_search_words_must_all_match(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame([], $this->fetchListedEmails($adminToken, ['search' => 'maria rizal']));
        $this->assertSame([self::MARIA['email']], $this->fetchListedEmails($adminToken, ['search' => '  maria   santos  ']));
    }

    public function test_empty_search_returns_all(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->fetchEmployeeList($adminToken, ['search' => ''])->assertOk()->assertJsonCount(3, 'employees');
        $this->fetchEmployeeList($adminToken, ['search' => '  '])->assertOk()->assertJsonCount(3, 'employees');
    }

    public function test_filter_by_role(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame(
            [self::JOSE['email'], self::ACTING_ADMIN['email']],
            $this->fetchListedEmails($adminToken, ['role' => Role::ADMIN]),
        );
        $this->assertSame(
            [self::JOSE['email'], self::MARIA['email']],
            $this->fetchListedEmails($adminToken, ['role' => Role::REGISTRAR]),
        );
    }

    public function test_filter_by_status(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame(
            [self::MARIA['email'], self::ACTING_ADMIN['email']],
            $this->fetchListedEmails($adminToken, ['status' => 'active']),
        );
        $this->assertSame([self::JOSE['email']], $this->fetchListedEmails($adminToken, ['status' => 'inactive']));
    }

    public function test_list_includes_inactive_employees_by_default(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $listResponse = $this->fetchEmployeeList($adminToken)->assertOk()->assertJsonPath('meta.total', 3);

        $joseRow = collect($listResponse->json('employees'))->firstWhere('email', self::JOSE['email']);
        $this->assertNotNull($joseRow);
        $this->assertFalse($joseRow['is_active']);
    }

    public function test_filters_and_search_combine(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->assertSame(
            [self::ACTING_ADMIN['email']],
            $this->fetchListedEmails($adminToken, ['role' => Role::ADMIN, 'status' => 'active']),
        );
        $this->fetchEmployeeList($adminToken, ['search' => 'school.test', 'role' => Role::REGISTRAR, 'status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('employees.0.email', self::JOSE['email']);
    }

    public function test_list_rejects_invalid_query_params(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $invalidQueryCases = [
            [['search' => ['maria']], 'search'],
            [['role' => [Role::ADMIN]], 'role'],
            [['status' => ['active']], 'status'],
            [['role' => 'teacher'], 'role'],
            [['role' => 'Admin'], 'role'],
            [['status' => 'all'], 'status'],
            [['search' => str_repeat('a', 256)], 'search'],
        ];

        foreach ($invalidQueryCases as [$invalidQuery, $errorKey]) {
            $this->fetchEmployeeList($adminToken, $invalidQuery)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$errorKey]);
        }
    }

    public function test_search_with_sql_metacharacters_is_safe(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $this->createFixtureEmployees();

        $this->fetchEmployeeList($adminToken, ['search' => "' OR 1=1 --"])
            ->assertOk()
            ->assertJsonPath('employees', []);
    }

    private function createFixtureEmployees(): void
    {
        User::factory()->registrar()->create(self::MARIA);
        User::factory()->admin()->registrar()->inactive()->create(self::JOSE);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExpectedRow(User $employee): array
    {
        $storedEmployee = $employee->fresh()->load('roles');

        return [
            'id' => $storedEmployee->id,
            'name' => $storedEmployee->name,
            'email' => $storedEmployee->email,
            'contact' => $storedEmployee->contact,
            'is_active' => true,
            'roles' => $storedEmployee->roles->pluck('name')->all(),
            'created_at' => $storedEmployee->created_at->toJSON(),
            'updated_at' => $storedEmployee->updated_at->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return list<string>
     */
    private function fetchListedEmails(string $token, array $queryParams): array
    {
        return array_column($this->fetchEmployeeList($token, $queryParams)->assertOk()->json('employees'), 'email');
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchEmployeeList(string $token, array $queryParams = []): TestResponse
    {
        return $this->withToken($token)->getJson(self::EMPLOYEES_URL.'?'.http_build_query($queryParams));
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
