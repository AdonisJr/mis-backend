<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'admin@gmail.com';

    private const ADMIN_PASSWORD = '123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.seed_admin.email' => self::ADMIN_EMAIL,
            'auth.seed_admin.password' => self::ADMIN_PASSWORD,
        ]);
    }

    public function test_seeder_creates_roles_and_admin(): void
    {
        $this->seed();

        $this->assertEqualsCanonicalizing(Role::STAFF_ROLES, Role::pluck('name')->all());
        $adminUser = User::where('email', self::ADMIN_EMAIL)->firstOrFail();
        $this->assertTrue($adminUser->hasAnyRole([Role::ADMIN]));
        $this->assertTrue(Hash::check(self::ADMIN_PASSWORD, $adminUser->password));
        $this->assertFalse(User::where('email', 'test@example.com')->exists());
    }

    public function test_seeded_admin_can_log_in(): void
    {
        $this->seed();

        $this->postJson('/api/mis/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.roles', [Role::ADMIN]);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(1, User::count());
        $this->assertSame(2, Role::count());
        $this->assertSame(1, DB::table('role_user')->count());
    }

    public function test_seeder_fails_fast_without_admin_config(): void
    {
        config(['auth.seed_admin.email' => null, 'auth.seed_admin.password' => null]);

        try {
            $this->seed();
            $this->fail('Seeder did not throw RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertSame('SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD must be set', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
    }
}
