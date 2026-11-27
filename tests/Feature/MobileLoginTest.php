<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class MobileLoginTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const LOGIN_URL = '/api/mis/mobile/login';

    private const STAFF_LOGIN_URL = '/api/mis/login';

    private const LRN = '100000000001';

    private const PASSWORD = 'temp-pass-1';

    private const DEVICE = 'pixel-8';

    private const MOBILE_STUDENT_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'grade_level', 'section', 'school_year'];

    private const FAILED_MESSAGE = 'These credentials do not match our records.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
    }

    /**
     * @return array<string, string>
     */
    private function buildCredentials(array $overrides = []): array
    {
        return ['lrn' => self::LRN, 'password' => self::PASSWORD, 'device_name' => self::DEVICE, ...$overrides];
    }

    private function createEnrolledStudentAccount(): User
    {
        $student = $this->enrollStudent(['lrn' => self::LRN], ['section_id' => $this->rizalSection->id])->student;

        return User::factory()->studentAccount($student)->create(['password' => self::PASSWORD]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailedBody(): array
    {
        return ['message' => self::FAILED_MESSAGE, 'errors' => ['lrn' => [self::FAILED_MESSAGE]]];
    }

    public function test_login_returns_token_user_student_and_flag(): void
    {
        $this->createEnrolledStudentAccount();

        $loginResponse = $this->postJson(self::LOGIN_URL, $this->buildCredentials())->assertOk();

        $this->assertSame(['token', 'user', 'student', 'must_change_password'], array_keys($loginResponse->json()));
        $loginResponse->assertJsonPath('user.roles', [Role::STUDENT])->assertJsonPath('user.email', null)->assertJsonPath('must_change_password', false);
        $this->assertSame(self::MOBILE_STUDENT_KEYS, array_keys($loginResponse->json('student')));
        $this->assertSame(['id' => $this->rizalSection->id, 'name' => self::RIZAL], $loginResponse->json('student.section'));
        $this->assertSame(self::DEVICE, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->firstOrFail()->name);
    }

    public function test_wrong_password_returns_422(): void
    {
        $this->createEnrolledStudentAccount();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['password' => 'wrong-pass-9']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn' => self::FAILED_MESSAGE]);
    }

    public function test_unknown_lrn_and_wrong_password_return_the_identical_body(): void
    {
        $this->createEnrolledStudentAccount();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['lrn' => '100000000099']))->assertUnprocessable()->assertExactJson($this->buildFailedBody());
        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['password' => 'wrong-pass-9']))->assertUnprocessable()->assertExactJson($this->buildFailedBody());
    }

    public function test_staff_user_linked_to_the_lrn_gets_the_identical_body(): void
    {
        $staffUser = User::factory()->admin()->create(['password' => self::PASSWORD]);
        Student::factory()->create(['lrn' => self::LRN])->forceFill(['user_id' => $staffUser->id])->save();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())->assertUnprocessable()->assertExactJson($this->buildFailedBody());
        $this->assertSame(0, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->count());
    }

    public function test_inactive_account_gets_the_deactivated_notice_and_no_token(): void
    {
        $this->createEnrolledStudentAccount()->forceFill(['is_active' => false])->save();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn' => AuthController::DEACTIVATED_MESSAGE]);

        $this->assertSame(0, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->count());
    }

    public function test_inactive_account_with_a_wrong_password_gets_the_generic_body(): void
    {
        $this->createEnrolledStudentAccount()->forceFill(['is_active' => false])->save();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['password' => 'wrong-pass-9']))->assertUnprocessable()->assertExactJson($this->buildFailedBody());
    }

    public function test_student_without_an_account_gets_the_identical_body(): void
    {
        Student::factory()->create(['lrn' => self::LRN]);

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())->assertUnprocessable()->assertExactJson($this->buildFailedBody());
    }

    public function test_missing_fields_return_422(): void
    {
        $this->postJson(self::LOGIN_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lrn', 'password', 'device_name']);
    }

    public function test_student_summary_is_null_safe_without_a_current_enrollment(): void
    {
        $student = Student::factory()->create(['lrn' => self::LRN]);
        User::factory()->studentAccount($student)->create(['password' => self::PASSWORD]);

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())
            ->assertOk()
            ->assertJsonPath('student.grade_level', null)
            ->assertJsonPath('student.section', null)
            ->assertJsonPath('student.school_year', null);
    }

    public function test_two_logins_create_two_tokens(): void
    {
        $this->createEnrolledStudentAccount();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())->assertOk();
        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['device_name' => 'iphone']))->assertOk();

        $this->assertSame(2, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->count());
    }

    public function test_student_account_with_an_email_cannot_use_staff_login(): void
    {
        $studentUser = $this->createEnrolledStudentAccount();
        $studentUser->forceFill(['email' => 'student@school.test'])->save();

        $this->postJson(self::STAFF_LOGIN_URL, ['email' => 'student@school.test', 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertExactJson(['message' => self::FAILED_MESSAGE, 'errors' => ['email' => [self::FAILED_MESSAGE]]]);
    }

    public function test_user_holding_student_and_staff_roles_cannot_use_staff_login(): void
    {
        $mixedUser = User::factory()->admin()->create(['email' => 'mixed@school.test', 'password' => self::PASSWORD]);
        $mixedUser->roles()->attach(Role::firstOrCreate(['name' => Role::STUDENT]));

        $this->postJson(self::STAFF_LOGIN_URL, ['email' => 'mixed@school.test', 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => self::FAILED_MESSAGE]);
    }
}
