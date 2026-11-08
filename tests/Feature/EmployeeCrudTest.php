<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    private const EMPLOYEES_URL = '/api/mis/employees';

    private const LOGIN_URL = '/api/mis/login';

    private const CURRENT_USER_URL = '/api/mis/user';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const PASSWORD = 'secret-pass-1';

    private const NEW_PASSWORD = 'new-secret-2';

    private const SHORT_PASSWORD = 'short7c';

    private const EIGHT_CHAR_PASSWORD = 'eightchr';

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const VALID_PAYLOAD = [
        'name' => 'Maria Santos',
        'email' => 'maria.santos@school.test',
        'contact' => '09171234567',
        'password' => self::PASSWORD,
        'password_confirmation' => self::PASSWORD,
        'roles' => [Role::REGISTRAR],
    ];

    private const EMPLOYEE_KEYS = ['id', 'name', 'email', 'contact', 'is_active', 'roles', 'created_at', 'updated_at'];

    private const PROFILE_KEYS = ['name', 'email', 'contact', 'roles'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Role::STAFF_ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName]);
        }
    }

    public function test_users_table_has_contact_and_is_active_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['contact', 'is_active']));

        $userColumns = collect(Schema::getColumns('users'));
        $this->assertTrue($userColumns->firstWhere('name', 'contact')['nullable']);
        $this->assertFalse($userColumns->firstWhere('name', 'is_active')['nullable']);
    }

    public function test_existing_users_default_to_active(): void
    {
        DB::table('users')->insert([
            'name' => 'Legacy Staff',
            'email' => 'legacy@school.test',
            'password' => Hash::make(self::PASSWORD),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyUser = User::where('email', 'legacy@school.test')->first();
        $this->assertTrue($legacyUser->is_active);
        $this->assertNull($legacyUser->contact);
    }

    public function test_admin_creates_employee(): void
    {
        $createResponse = $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('employee.name', self::VALID_PAYLOAD['name'])
            ->assertJsonPath('employee.email', self::VALID_PAYLOAD['email'])
            ->assertJsonPath('employee.contact', self::VALID_PAYLOAD['contact'])
            ->assertJsonPath('employee.is_active', true)
            ->assertJsonPath('employee.roles', [Role::REGISTRAR])
            ->assertJsonMissingPath('employee.password');

        $this->assertSame(self::EMPLOYEE_KEYS, array_keys($createResponse->json('employee')));
        $createdEmployee = User::findOrFail($createResponse->json('employee.id'));
        $this->assertTrue(Hash::check(self::PASSWORD, $createdEmployee->password));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_create_with_both_roles_returns_sorted_roles(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'roles' => [Role::REGISTRAR, Role::ADMIN]])
            ->assertCreated()
            ->assertJsonPath('employee.roles', [Role::ADMIN, Role::REGISTRAR]);
    }

    public function test_create_without_contact_stores_null(): void
    {
        $adminToken = $this->createActingAdminToken();

        $omittedResponse = $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, Arr::except(self::VALID_PAYLOAD, 'contact'))
            ->assertCreated()
            ->assertJsonPath('employee.contact', null);
        $this->assertNull(User::findOrFail($omittedResponse->json('employee.id'))->contact);

        $emptyResponse = $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'email' => 'empty.contact@school.test', 'contact' => ''])
            ->assertCreated()
            ->assertJsonPath('employee.contact', null);
        $this->assertNull(User::findOrFail($emptyResponse->json('employee.id'))->contact);
    }

    public function test_create_requires_name_email_password_and_roles(): void
    {
        $adminToken = $this->createActingAdminToken();

        foreach (['name', 'email', 'password', 'roles'] as $requiredField) {
            $this->withToken($adminToken)
                ->postJson(self::EMPLOYEES_URL, Arr::except(self::VALID_PAYLOAD, $requiredField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_rejects_invalid_email(): void
    {
        $adminToken = $this->createActingAdminToken();

        foreach (['not-an-email', 'maria@', str_repeat('a', 244).'@school.test'] as $invalidEmail) {
            $this->withToken($adminToken)
                ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'email' => $invalidEmail])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => self::VALID_PAYLOAD['email']]);

        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, self::VALID_PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_create_rejects_short_password(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [
                ...self::VALID_PAYLOAD,
                'password' => self::SHORT_PASSWORD,
                'password_confirmation' => self::SHORT_PASSWORD,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_accepts_eight_character_password(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [
                ...self::VALID_PAYLOAD,
                'password' => self::EIGHT_CHAR_PASSWORD,
                'password_confirmation' => self::EIGHT_CHAR_PASSWORD,
            ])
            ->assertCreated();
    }

    public function test_create_rejects_unconfirmed_password(): void
    {
        $adminToken = $this->createActingAdminToken();

        $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, Arr::except(self::VALID_PAYLOAD, 'password_confirmation'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'password_confirmation' => 'different-pass'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_rejects_invalid_roles(): void
    {
        $adminToken = $this->createActingAdminToken();
        $invalidRoleCases = [
            [[], 'roles'],
            [Role::ADMIN, 'roles'],
            [['a' => Role::ADMIN], 'roles'],
            [['superadmin'], 'roles.0'],
            [['Admin'], 'roles.0'],
            [['student'], 'roles.0'],
            [[Role::ADMIN, Role::ADMIN], 'roles.1'],
        ];

        foreach ($invalidRoleCases as [$invalidRoles, $errorKey]) {
            $this->withToken($adminToken)
                ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'roles' => $invalidRoles])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$errorKey]);
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_rejects_role_missing_from_roles_table(): void
    {
        $adminToken = $this->createActingAdminToken();
        Role::where('name', Role::REGISTRAR)->delete();

        $this->withToken($adminToken)
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'roles' => [Role::REGISTRAR]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles.0']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_rejects_oversized_name_and_contact(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'name' => str_repeat('a', 256), 'contact' => str_repeat('9', 21)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'contact']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_create_accepts_twenty_character_contact(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'contact' => str_repeat('9', 20)])
            ->assertCreated()
            ->assertJsonPath('employee.contact', str_repeat('9', 20));
    }

    public function test_create_ignores_is_active_and_id(): void
    {
        $createResponse = $this->withToken($this->createActingAdminToken())
            ->postJson(self::EMPLOYEES_URL, [...self::VALID_PAYLOAD, 'is_active' => false, 'id' => 5000])
            ->assertCreated()
            ->assertJsonPath('employee.is_active', true);

        $this->assertNotSame(5000, $createResponse->json('employee.id'));
    }

    public function test_admin_updates_employee(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $updatedProfile = [
            'name' => 'Maria Santos-Reyes',
            'email' => 'maria.reyes@school.test',
            'contact' => '09181234567',
            'roles' => [Role::ADMIN, Role::REGISTRAR],
        ];

        $updateResponse = $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $updatedProfile)
            ->assertOk()
            ->assertJsonPath('employee.name', $updatedProfile['name'])
            ->assertJsonPath('employee.email', $updatedProfile['email'])
            ->assertJsonPath('employee.contact', $updatedProfile['contact'])
            ->assertJsonPath('employee.roles', $updatedProfile['roles']);

        $this->assertSame(self::EMPLOYEE_KEYS, array_keys($updateResponse->json('employee')));
        $this->assertDatabaseHas('users', [
            'id' => $targetEmployee->id,
            'name' => $updatedProfile['name'],
            'email' => $updatedProfile['email'],
            'contact' => $updatedProfile['contact'],
        ]);
    }

    public function test_update_replaces_roles(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = User::factory()->admin()->create(['email' => 'target.admin@school.test']);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload([
                'email' => 'target.admin@school.test',
                'roles' => [Role::REGISTRAR],
            ]))
            ->assertOk()
            ->assertJsonPath('employee.roles', [Role::REGISTRAR]);

        $this->assertSame(1, DB::table('role_user')->where('user_id', $targetEmployee->id)->count());
    }

    public function test_update_allows_own_unchanged_email(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload(['name' => 'Maria S. Santos']))
            ->assertOk()
            ->assertJsonPath('employee.email', self::VALID_PAYLOAD['email'])
            ->assertJsonPath('employee.name', 'Maria S. Santos');
    }

    public function test_update_rejects_other_employees_email(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        User::factory()->create(['email' => 'taken@school.test']);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload(['email' => 'taken@school.test']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(self::VALID_PAYLOAD['email'], $targetEmployee->fresh()->email);
    }

    public function test_update_does_not_change_password(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $this->createTokenFor($targetEmployee);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload([
                'password' => 'changed-pass-9',
                'password_confirmation' => 'changed-pass-9',
            ]))
            ->assertOk();

        $this->assertTrue(Hash::check(self::PASSWORD, $targetEmployee->fresh()->password));
        $this->assertSame(1, $targetEmployee->tokens()->count());
    }

    public function test_update_contact_null_clears_and_omitted_keeps(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $targetUrl = self::EMPLOYEES_URL.'/'.$targetEmployee->id;

        $this->withToken($adminToken)
            ->putJson($targetUrl, Arr::except($this->buildUpdatePayload([]), 'contact'))
            ->assertOk()
            ->assertJsonPath('employee.contact', self::VALID_PAYLOAD['contact']);
        $this->assertSame(self::VALID_PAYLOAD['contact'], $targetEmployee->fresh()->contact);

        $this->withToken($adminToken)
            ->putJson($targetUrl, $this->buildUpdatePayload(['contact' => null]))
            ->assertOk()
            ->assertJsonPath('employee.contact', null);
        $this->assertNull($targetEmployee->fresh()->contact);
    }

    public function test_update_rejects_invalid_fields(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $invalidFieldCases = [
            [['name' => ''], 'name'],
            [['email' => 'not-an-email'], 'email'],
            [['roles' => []], 'roles'],
            [['roles' => ['superadmin']], 'roles.0'],
        ];

        foreach ($invalidFieldCases as [$invalidOverrides, $errorKey]) {
            $this->withToken($adminToken)
                ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload($invalidOverrides))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$errorKey]);
        }

        $reloadedEmployee = $targetEmployee->fresh();
        $this->assertSame(self::VALID_PAYLOAD['name'], $reloadedEmployee->name);
        $this->assertSame(self::VALID_PAYLOAD['email'], $reloadedEmployee->email);
        $this->assertSame([Role::REGISTRAR], $reloadedEmployee->roles->pluck('name')->all());
    }

    public function test_update_works_on_inactive_employee(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $targetEmployee->forceFill(['is_active' => false])->save();

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload(['name' => 'Maria Inactive']))
            ->assertOk()
            ->assertJsonPath('employee.name', 'Maria Inactive')
            ->assertJsonPath('employee.is_active', false);

        $this->assertFalse($targetEmployee->fresh()->is_active);
    }

    public function test_update_ignores_is_active(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id, $this->buildUpdatePayload(['is_active' => false]))
            ->assertOk()
            ->assertJsonPath('employee.is_active', true);

        $this->assertTrue($targetEmployee->fresh()->is_active);
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->putJson(self::EMPLOYEES_URL.'/'.self::MISSING_ID, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'roles']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createActingAdminToken())
            ->putJson(self::EMPLOYEES_URL.'/'.self::MISSING_ID, $this->buildUpdatePayload([]))
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.User::class.'] '.self::MISSING_ID]);
    }

    public function test_admin_sets_employee_password(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);
        $targetEmployee = $this->createRegistrarTarget();
        $oldTargetToken = $this->createTokenFor($targetEmployee);
        $this->createTokenFor($targetEmployee);

        $passwordResponse = $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id.'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(self::EMPLOYEE_KEYS, array_keys($passwordResponse->json('employee')));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $targetEmployee->fresh()->password));
        $this->assertSame(0, $targetEmployee->tokens()->count());
        $this->assertSame(1, $actingAdmin->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($oldTargetToken)->getJson(self::CURRENT_USER_URL)->assertUnauthorized();

        $this->postJson(self::LOGIN_URL, ['email' => $targetEmployee->email, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_set_password_rejects_invalid_password(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $this->createTokenFor($targetEmployee);
        $invalidPasswordBodies = [
            ['password_confirmation' => self::NEW_PASSWORD],
            ['password' => self::SHORT_PASSWORD, 'password_confirmation' => self::SHORT_PASSWORD],
            ['password' => self::NEW_PASSWORD],
            ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-pass'],
        ];

        foreach ($invalidPasswordBodies as $invalidPasswordBody) {
            $this->withToken($adminToken)
                ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id.'/password', $invalidPasswordBody)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['password']);
        }

        $this->assertTrue(Hash::check(self::PASSWORD, $targetEmployee->fresh()->password));
        $this->assertSame(1, $targetEmployee->tokens()->count());
    }

    public function test_set_password_accepts_eight_character_password(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id.'/password', [
                'password' => self::EIGHT_CHAR_PASSWORD,
                'password_confirmation' => self::EIGHT_CHAR_PASSWORD,
            ])
            ->assertOk();

        $this->assertTrue(Hash::check(self::EIGHT_CHAR_PASSWORD, $targetEmployee->fresh()->password));
    }

    public function test_admin_resets_own_password_and_loses_own_tokens(): void
    {
        $actingAdmin = $this->createActingAdmin();
        $adminToken = $this->createTokenFor($actingAdmin);

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$actingAdmin->id.'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(0, $actingAdmin->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($adminToken)->getJson(self::CURRENT_USER_URL)->assertUnauthorized();
    }

    public function test_set_password_works_on_inactive_employee(): void
    {
        $adminToken = $this->createActingAdminToken();
        $targetEmployee = $this->createRegistrarTarget();
        $targetEmployee->forceFill(['is_active' => false])->save();

        $this->withToken($adminToken)
            ->putJson(self::EMPLOYEES_URL.'/'.$targetEmployee->id.'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('employee.is_active', false);

        $this->assertFalse($targetEmployee->fresh()->is_active);
    }

    public function test_set_password_validates_before_lookup(): void
    {
        $this->withToken($this->createActingAdminToken())
            ->putJson(self::EMPLOYEES_URL.'/'.self::MISSING_ID.'/password', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_set_password_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createActingAdminToken())
            ->putJson(self::EMPLOYEES_URL.'/'.self::MISSING_ID.'/password', [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.User::class.'] '.self::MISSING_ID]);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $adminToken = $this->createActingAdminToken();

        foreach (['/abc', '/abc/password', '/abc/deactivate', '/abc/reactivate'] as $nonNumericPath) {
            $this->withToken($adminToken)
                ->putJson(self::EMPLOYEES_URL.$nonNumericPath, $this->buildUpdatePayload([]))
                ->assertNotFound();
        }
    }

    private function createRegistrarTarget(): User
    {
        return User::factory()->registrar()->create([
            ...Arr::only(self::VALID_PAYLOAD, ['name', 'email', 'contact']),
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildUpdatePayload(array $overrides): array
    {
        return [...Arr::only(self::VALID_PAYLOAD, self::PROFILE_KEYS), ...$overrides];
    }

    private function createActingAdminToken(): string
    {
        return $this->createTokenFor($this->createActingAdmin());
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
