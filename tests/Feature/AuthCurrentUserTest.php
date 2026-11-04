<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthCurrentUserTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_USER_URL = '/api/mis/user';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const TOKEN_NAME = 'test';

    private const AUTHORIZATION_HEADER = 'Authorization';

    public function test_admin_gets_current_user_with_roles(): void
    {
        $adminUser = User::factory()->admin()->create();

        $this->withToken($adminUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertOk()
            ->assertExactJson(['user' => [
                'id' => $adminUser->id,
                'name' => $adminUser->name,
                'email' => $adminUser->email,
                'roles' => [Role::ADMIN],
            ]]);
    }

    public function test_registrar_gets_current_user_with_roles(): void
    {
        $registrarUser = User::factory()->registrar()->create();

        $this->withToken($registrarUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertOk()
            ->assertJsonPath('user.id', $registrarUser->id)
            ->assertJsonPath('user.roles', [Role::REGISTRAR]);
    }

    public function test_user_with_both_roles_passes(): void
    {
        $staffUser = User::factory()->registrar()->admin()->create();

        $this->withToken($staffUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertOk()
            ->assertJsonPath('user.roles', [Role::ADMIN, Role::REGISTRAR]);
    }

    public function test_missing_token_returns_401_json(): void
    {
        $this->getJson(self::CURRENT_USER_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_missing_token_without_accept_header_returns_401_json(): void
    {
        $this->get(self::CURRENT_USER_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_unknown_token_returns_401(): void
    {
        User::factory()->admin()->create();

        $this->withHeader(self::AUTHORIZATION_HEADER, 'Bearer 999|garbage')
            ->getJson(self::CURRENT_USER_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->withHeader(self::AUTHORIZATION_HEADER, 'Bearer ')
            ->getJson(self::CURRENT_USER_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_user_without_roles_returns_403(): void
    {
        $roleLessUser = User::factory()->create();

        $this->withToken($roleLessUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_user_with_only_non_staff_role_returns_403(): void
    {
        $studentUser = User::factory()->create();
        $studentUser->roles()->attach(Role::factory()->create(['name' => 'student']));

        $this->withToken($studentUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_two_admins_share_one_admin_role(): void
    {
        User::factory()->count(2)->admin()->create();

        $this->assertSame(1, Role::where('name', Role::ADMIN)->count());
        $this->assertSame(2, DB::table('role_user')->count());
    }

    public function test_deleting_user_removes_role_links(): void
    {
        $adminUser = User::factory()->admin()->create();
        $adminUser->createToken(self::TOKEN_NAME);

        $adminUser->delete();

        $this->assertSame(0, DB::table('role_user')->count());
        $this->assertSame(1, Role::where('name', Role::ADMIN)->count());
    }

    public function test_device_routes_are_not_added(): void
    {
        $this->postJson('/api/scan', ['rfid' => 'AB:CD:EF:01'])->assertNotFound();
        $this->post('/api/rfid/scan', ['rfid' => 'AB:CD:EF:01'])->assertNotFound();
    }

    public function test_legacy_api_user_stub_is_removed(): void
    {
        $adminUser = User::factory()->admin()->create();

        $this->withToken($adminUser->createToken(self::TOKEN_NAME)->plainTextToken)
            ->getJson('/api/user')
            ->assertNotFound();
    }
}
