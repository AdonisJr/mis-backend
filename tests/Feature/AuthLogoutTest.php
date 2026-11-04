<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const LOGOUT_URL = '/api/mis/logout';

    private const CURRENT_USER_URL = '/api/mis/user';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const TOKEN_NAME = 'test';

    public function test_logout_returns_204_and_deletes_current_token(): void
    {
        $adminUser = User::factory()->admin()->create();
        $plainTextToken = $adminUser->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->withToken($plainTextToken)
            ->postJson(self::LOGOUT_URL)
            ->assertNoContent();

        $this->assertSame(0, $adminUser->tokens()->count());
    }

    public function test_revoked_token_returns_401_on_next_request(): void
    {
        $adminUser = User::factory()->admin()->create();
        $plainTextToken = $adminUser->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->withToken($plainTextToken)->postJson(self::LOGOUT_URL)->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($plainTextToken)
            ->getJson(self::CURRENT_USER_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $adminUser = User::factory()->admin()->create();
        $firstToken = $adminUser->createToken('first')->plainTextToken;
        $secondToken = $adminUser->createToken('second')->plainTextToken;

        $this->withToken($firstToken)->postJson(self::LOGOUT_URL)->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->assertSame(['second'], $adminUser->tokens()->pluck('name')->all());
        $this->withToken($secondToken)->getJson(self::CURRENT_USER_URL)->assertOk();
    }

    public function test_logout_without_token_returns_401(): void
    {
        $this->postJson(self::LOGOUT_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_user_without_roles_cannot_logout_and_token_survives(): void
    {
        $roleLessUser = User::factory()->create();
        $plainTextToken = $roleLessUser->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->withToken($plainTextToken)
            ->postJson(self::LOGOUT_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertSame(1, $roleLessUser->tokens()->count());
    }
}
