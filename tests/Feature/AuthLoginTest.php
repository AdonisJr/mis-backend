<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_URL = '/api/mis/login';

    private const CURRENT_USER_URL = '/api/mis/user';

    private const PASSWORD = 'secret-password';

    private const WRONG_PASSWORD = 'wrong';

    private const SHORT_PASSWORD = 'secret';

    private const SAMPLE_EMAIL = 'admin@gmail.com';

    public function test_successful_login_returns_token_and_user(): void
    {
        $adminUser = User::factory()->admin()->create(['password' => self::PASSWORD]);

        $loginResponse = $this->postJson(self::LOGIN_URL, [
            'email' => $adminUser->email,
            'password' => self::PASSWORD,
        ]);

        $loginResponse->assertOk()
            ->assertExactJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']])
            ->assertJsonPath('user.id', $adminUser->id)
            ->assertJsonPath('user.email', $adminUser->email)
            ->assertJsonPath('user.roles', [Role::ADMIN])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');
        $this->assertIsString($loginResponse->json('token'));

        $this->withToken($loginResponse->json('token'))
            ->getJson(self::CURRENT_USER_URL)
            ->assertOk()
            ->assertJsonPath('user.id', $adminUser->id);
    }

    public function test_wrong_password_returns_422_json(): void
    {
        $adminUser = User::factory()->admin()->create(['password' => self::PASSWORD]);

        $this->postJson(self::LOGIN_URL, ['email' => $adminUser->email, 'password' => self::WRONG_PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('auth.failed'))
            ->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_unknown_email_and_wrong_password_return_identical_422(): void
    {
        $adminUser = User::factory()->admin()->create(['password' => self::PASSWORD]);

        $wrongPasswordResponse = $this->postJson(self::LOGIN_URL, [
            'email' => $adminUser->email,
            'password' => self::WRONG_PASSWORD,
        ]);
        $unknownEmailResponse = $this->postJson(self::LOGIN_URL, [
            'email' => 'nobody@example.com',
            'password' => self::WRONG_PASSWORD,
        ]);

        $wrongPasswordResponse->assertUnprocessable();
        $unknownEmailResponse->assertUnprocessable();
        $this->assertSame($wrongPasswordResponse->json(), $unknownEmailResponse->json());
    }

    public function test_missing_fields_return_422(): void
    {
        $this->postJson(self::LOGIN_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * @param  array<string, mixed>  $loginPayload
     */
    #[DataProvider('invalidPayloadProvider')]
    public function test_invalid_payload_returns_422(array $loginPayload, string $invalidField): void
    {
        $this->postJson(self::LOGIN_URL, $loginPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$invalidField]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'email not an email' => [['email' => 'not-an-email', 'password' => self::SHORT_PASSWORD], 'email'],
            'email too long' => [['email' => str_repeat('a', 246).'@gmail.com', 'password' => self::SHORT_PASSWORD], 'email'],
            'password too long' => [['email' => self::SAMPLE_EMAIL, 'password' => str_repeat('a', 256)], 'password'],
            'password as array' => [['email' => self::SAMPLE_EMAIL, 'password' => [self::SHORT_PASSWORD]], 'password'],
        ];
    }

    public function test_login_without_accept_header_returns_422_json(): void
    {
        $this->post(self::LOGIN_URL)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_role_less_user_can_log_in_and_gets_empty_roles(): void
    {
        $roleLessUser = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson(self::LOGIN_URL, ['email' => $roleLessUser->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.roles', []);
    }

    public function test_two_logins_issue_two_independent_tokens(): void
    {
        $adminUser = User::factory()->admin()->create(['password' => self::PASSWORD]);
        $credentials = ['email' => $adminUser->email, 'password' => self::PASSWORD];

        $firstToken = $this->postJson(self::LOGIN_URL, $credentials)->json('token');
        $secondToken = $this->postJson(self::LOGIN_URL, $credentials)->json('token');

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertSame(2, $adminUser->tokens()->count());

        $this->withToken($firstToken)->getJson(self::CURRENT_USER_URL)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($secondToken)->getJson(self::CURRENT_USER_URL)->assertOk();
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $inactiveUser = User::factory()->admin()->inactive()->create(['password' => self::PASSWORD]);

        $this->postJson(self::LOGIN_URL, ['email' => $inactiveUser->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => AuthController::DEACTIVATED_MESSAGE,
                'errors' => ['email' => [AuthController::DEACTIVATED_MESSAGE]],
            ]);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_inactive_account_with_wrong_password_gets_generic_failure(): void
    {
        $inactiveUser = User::factory()->admin()->inactive()->create(['password' => self::PASSWORD]);

        $this->postJson(self::LOGIN_URL, ['email' => $inactiveUser->email, 'password' => self::WRONG_PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('auth.failed'))
            ->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertSame(0, PersonalAccessToken::count());
    }
}
