<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeStatusTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const LOGIN_URL = '/api/mis/login';

    private const CURRENT_USER_URL = '/api/mis/user';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const PASSWORD = 'secret-pass-1';

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

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

    public function test_admin_deactivates_employee(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);
        $targetEmployee = User::factory()->registrar()->create();
        $this->createTokenFor($targetEmployee);
        $this->createTokenFor($targetEmployee);

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetEmployee->id, 'deactivate'))
            ->assertOk()
            ->assertJsonPath('employee.id', $targetEmployee->id)
            ->assertJsonPath('employee.is_active', false);

        $this->assertFalse($targetEmployee->fresh()->is_active);
        $this->assertSame(0, $targetEmployee->tokens()->count());
        $this->assertSame(1, $actingAdmin->tokens()->count());
    }

    public function test_deactivated_employee_token_stops_working(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetEmployee = User::factory()->registrar()->create();
        $targetToken = $this->createTokenFor($targetEmployee);

        $this->withToken($targetToken)->getJson(self::CURRENT_USER_URL)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetEmployee->id, 'deactivate'))
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($targetToken)->getJson(self::CURRENT_USER_URL)->assertUnauthorized();
    }

    public function test_admin_reactivates_employee(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetEmployee = User::factory()->registrar()->inactive()->create();

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetEmployee->id, 'reactivate'))
            ->assertOk()
            ->assertJsonPath('employee.is_active', true);

        $this->assertTrue($targetEmployee->fresh()->is_active);
        $this->assertSame(0, $targetEmployee->tokens()->count());
    }

    public function test_deactivated_employee_cannot_log_in_until_reactivated(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetEmployee = User::factory()->registrar()->create(['password' => self::PASSWORD]);
        $credentials = ['email' => $targetEmployee->email, 'password' => self::PASSWORD];

        $this->withToken($adminToken)->putJson($this->buildStatusUrl($targetEmployee->id, 'deactivate'))->assertOk();

        $this->postJson(self::LOGIN_URL, $credentials)
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => AuthController::DEACTIVATED_MESSAGE,
                'errors' => ['email' => [AuthController::DEACTIVATED_MESSAGE]],
            ]);
        $this->assertSame(0, $targetEmployee->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->putJson($this->buildStatusUrl($targetEmployee->id, 'reactivate'))->assertOk();

        $loginResponse = $this->postJson(self::LOGIN_URL, $credentials)->assertOk();
        $this->assertIsString($loginResponse->json('token'));
    }

    public function test_deactivate_is_idempotent(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetEmployee = User::factory()->registrar()->inactive()->create();

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetEmployee->id, 'deactivate'))
            ->assertOk()
            ->assertJsonPath('employee.is_active', false);

        $this->assertFalse($targetEmployee->fresh()->is_active);
    }

    public function test_reactivate_is_idempotent(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetEmployee = User::factory()->registrar()->create();

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetEmployee->id, 'reactivate'))
            ->assertOk()
            ->assertJsonPath('employee.is_active', true);

        $this->assertTrue($targetEmployee->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($actingAdmin->id, 'deactivate'))
            ->assertUnprocessable()
            ->assertExactJson(['message' => EmployeeController::SELF_DEACTIVATE_MESSAGE]);

        $this->assertTrue($actingAdmin->fresh()->is_active);
        $this->assertSame(1, $actingAdmin->tokens()->count());
    }

    public function test_last_active_admin_cannot_be_deactivated(): void
    {
        $inactiveCallerToken = $this->createInactiveCallerToken();
        $soleActiveAdmin = User::factory()->admin()->create();
        $this->createTokenFor($soleActiveAdmin);
        User::factory()->admin()->inactive()->create();

        $this->withToken($inactiveCallerToken)
            ->putJson($this->buildStatusUrl($soleActiveAdmin->id, 'deactivate'))
            ->assertUnprocessable()
            ->assertExactJson(['message' => EmployeeController::LAST_ADMIN_DEACTIVATE_MESSAGE]);

        $this->assertTrue($soleActiveAdmin->fresh()->is_active);
        $this->assertSame(1, $soleActiveAdmin->tokens()->count());
    }

    public function test_admin_can_be_deactivated_when_another_active_admin_exists(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetAdmin = User::factory()->admin()->create();

        $this->withToken($adminToken)
            ->putJson($this->buildStatusUrl($targetAdmin->id, 'deactivate'))
            ->assertOk()
            ->assertJsonPath('employee.is_active', false);

        $this->assertFalse($targetAdmin->fresh()->is_active);
    }

    public function test_admin_cannot_remove_own_admin_role(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$actingAdmin->id, [
                ...self::VALID_PAYLOAD,
                'name' => 'Renamed Admin',
                'email' => self::ACTING_ADMIN['email'],
                'roles' => [Role::REGISTRAR],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => EmployeeController::SELF_ADMIN_ROLE_MESSAGE,
                'errors' => ['roles' => [EmployeeController::SELF_ADMIN_ROLE_MESSAGE]],
            ]);

        $this->assertSame(self::ACTING_ADMIN['name'], $actingAdmin->fresh()->name);
        $this->assertTrue($actingAdmin->hasAnyRole([Role::ADMIN]));
    }

    public function test_admin_can_update_own_profile_keeping_admin_role(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$actingAdmin->id, [
                ...self::VALID_PAYLOAD,
                'name' => 'Renamed Admin',
                'email' => self::ACTING_ADMIN['email'],
                'roles' => [Role::ADMIN],
            ])
            ->assertOk()
            ->assertJsonPath('employee.name', 'Renamed Admin')
            ->assertJsonPath('employee.roles', [Role::ADMIN]);
    }

    public function test_last_active_admin_cannot_lose_admin_role(): void
    {
        $inactiveCallerToken = $this->createInactiveCallerToken();
        $soleActiveAdmin = User::factory()->admin()->create(['name' => 'Sole Admin', 'email' => 'sole@admin.test']);
        User::factory()->admin()->inactive()->create();

        $this->withToken($inactiveCallerToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$soleActiveAdmin->id, [
                ...self::VALID_PAYLOAD,
                'name' => 'Renamed Sole Admin',
                'email' => 'sole@admin.test',
                'roles' => [Role::REGISTRAR],
            ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => EmployeeController::LAST_ADMIN_ROLE_MESSAGE,
                'errors' => ['roles' => [EmployeeController::LAST_ADMIN_ROLE_MESSAGE]],
            ]);

        $this->assertSame('Sole Admin', $soleActiveAdmin->fresh()->name);
        $this->assertTrue($soleActiveAdmin->hasAnyRole([Role::ADMIN]));
    }

    public function test_admin_role_can_be_removed_when_another_active_admin_exists(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $targetAdmin = User::factory()->admin()->create(['email' => 'target@admin.test']);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetAdmin->id, [...self::VALID_PAYLOAD, 'email' => 'target@admin.test'])
            ->assertOk()
            ->assertJsonPath('employee.roles', [Role::REGISTRAR]);
    }

    public function test_inactive_admin_can_lose_admin_role(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $inactiveAdmin = User::factory()->admin()->inactive()->create(['email' => 'inactive@admin.test']);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$inactiveAdmin->id, [...self::VALID_PAYLOAD, 'email' => 'inactive@admin.test'])
            ->assertOk()
            ->assertJsonPath('employee.roles', [Role::REGISTRAR]);
    }

    public function test_status_routes_missing_id_return_404(): void
    {
        config(['app.debug' => false]);
        $adminToken = $this->createTokenFor($this->createActingAdmin());

        foreach (['deactivate', 'reactivate'] as $statusAction) {
            $this->withToken($adminToken)
                ->putJson($this->buildStatusUrl(self::MISSING_ID, $statusAction))
                ->assertNotFound()
                ->assertExactJson(['message' => 'No query results for model ['.User::class.'] '.self::MISSING_ID]);
        }
    }

    private function buildStatusUrl(int $employeeId, string $statusAction): string
    {
        return self::EMPLOYEES_URL.'/'.$employeeId.'/'.$statusAction;
    }

    private function createInactiveCallerToken(): string
    {
        $actingAdmin = $this->createActingAdmin();
        $callerToken = $this->createTokenFor($actingAdmin);
        $actingAdmin->forceFill(['is_active' => false])->save();

        return $callerToken;
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
