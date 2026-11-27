<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class MobileProfileTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const ME_URL = '/api/mis/mobile/me';

    private const PASSWORD_URL = '/api/mis/mobile/password';

    private const LOGOUT_URL = '/api/mis/mobile/logout';

    private const GRADES_URL = '/api/mis/mobile/grades';

    private const LRN = '100000000001';

    private const OLD_PASSWORD = 'temp-pass-1';

    private const NEW_PASSWORD = 'new-secret-9';

    private const SESSION_KEYS = ['user', 'student', 'must_change_password'];

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
        $student = $this->enrollStudent(['lrn' => self::LRN], ['section_id' => $this->rizalSection->id])->student;
        $this->studentUser = User::factory()->studentAccount($student)->create(['password' => self::OLD_PASSWORD]);
    }

    /**
     * @return array<string, string>
     */
    private function buildPasswordPayload(array $overrides = []): array
    {
        return ['current_password' => self::OLD_PASSWORD, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD, ...$overrides];
    }

    public function test_me_returns_the_user_student_and_flag(): void
    {
        $meResponse = $this->withToken($this->createTokenFor($this->studentUser))->getJson(self::ME_URL)->assertOk();

        $this->assertSame(self::SESSION_KEYS, array_keys($meResponse->json()));
        $meResponse
            ->assertJsonPath('user.id', $this->studentUser->id)
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('student.lrn', self::LRN)
            ->assertJsonPath('student.section.name', self::RIZAL)
            ->assertJsonPath('must_change_password', false);
    }

    public function test_me_is_allowed_while_the_password_must_change(): void
    {
        $this->studentUser->forceFill(['must_change_password' => true])->save();

        $this->withToken($this->createTokenFor($this->studentUser))->getJson(self::ME_URL)->assertOk()->assertJsonPath('must_change_password', true);
    }

    public function test_password_change_stores_the_new_password_and_clears_the_flag(): void
    {
        $this->studentUser->forceFill(['must_change_password' => true])->save();
        $studentToken = $this->createTokenFor($this->studentUser);

        $changeResponse = $this->withToken($studentToken)->putJson(self::PASSWORD_URL, $this->buildPasswordPayload())->assertOk();

        $this->assertSame(self::SESSION_KEYS, array_keys($changeResponse->json()));
        $changeResponse->assertJsonPath('must_change_password', false);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->studentUser->refresh()->password));
        $this->assertFalse($this->studentUser->must_change_password);
        $this->assertSame(1, $this->studentUser->tokens()->count());
    }

    public function test_wrong_current_password_returns_422(): void
    {
        $this->withToken($this->createTokenFor($this->studentUser))->putJson(self::PASSWORD_URL, $this->buildPasswordPayload(['current_password' => 'wrong-pass-9']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'The password is incorrect.']);
    }

    public function test_invalid_new_passwords_return_422(): void
    {
        $studentToken = $this->createTokenFor($this->studentUser);
        $invalidOverrides = [
            ['password' => 'short', 'password_confirmation' => 'short'],
            ['password_confirmation' => 'does-not-match'],
            ['password' => self::OLD_PASSWORD, 'password_confirmation' => self::OLD_PASSWORD],
        ];

        foreach ($invalidOverrides as $overrides) {
            $this->withToken($studentToken)->putJson(self::PASSWORD_URL, $this->buildPasswordPayload($overrides))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['password']);
        }
    }

    public function test_password_change_requires_both_passwords(): void
    {
        $this->withToken($this->createTokenFor($this->studentUser))->putJson(self::PASSWORD_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password', 'password']);
    }

    public function test_gated_route_returns_403_until_password_changed(): void
    {
        $this->studentUser->forceFill(['must_change_password' => true])->save();
        $studentToken = $this->createTokenFor($this->studentUser);

        $this->withToken($studentToken)->getJson(self::GRADES_URL)
            ->assertForbidden()
            ->assertExactJson(['message' => EnsurePasswordChanged::CHANGE_REQUIRED_MESSAGE]);

        $this->withToken($studentToken)->putJson(self::PASSWORD_URL, $this->buildPasswordPayload())->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($studentToken)->getJson(self::GRADES_URL)->assertOk();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $firstToken = $this->createTokenFor($this->studentUser);
        $this->createTokenFor($this->studentUser);

        $this->withToken($firstToken)->postJson(self::LOGOUT_URL)->assertNoContent();

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $this->studentUser->id)->count());
    }

    public function test_logout_is_allowed_while_the_password_must_change(): void
    {
        $this->studentUser->forceFill(['must_change_password' => true])->save();

        $this->withToken($this->createTokenFor($this->studentUser))->postJson(self::LOGOUT_URL)->assertNoContent();
    }

    public function test_guest_gets_401_on_the_four_mobile_routes(): void
    {
        $this->getJson(self::ME_URL)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        $this->putJson(self::PASSWORD_URL)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        $this->postJson(self::LOGOUT_URL)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
        $this->getJson(self::GRADES_URL)->assertUnauthorized()->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_me_returns_404_when_the_linked_student_was_removed(): void
    {
        $studentToken = $this->createTokenFor($this->studentUser);
        $this->studentUser->student()->firstOrFail()->enrollments()->delete();
        $this->studentUser->student()->delete();

        $this->withToken($studentToken)->getJson(self::ME_URL)->assertNotFound();
    }
}
