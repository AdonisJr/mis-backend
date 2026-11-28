<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Concerns\InteractsWithClassLists;
use Tests\TestCase;

class MobileParentLoginTest extends TestCase
{
    use InteractsWithClassLists;
    use RefreshDatabase;

    private const LOGIN_URL = '/api/mis/mobile/login';

    private const STAFF_LOGIN_URL = '/api/mis/login';

    private const NUMBER = '09170000001';

    private const LRN = '100000000001';

    private const PASSWORD = 'temp-pass-1';

    private const DEVICE = 'pixel-8';

    private const FAILED_MESSAGE = 'These credentials do not match our records.';

    private const MOBILE_CHILD_KEYS = ['id', 'lrn', 'first_name', 'middle_name', 'last_name', 'grade_level', 'section', 'school_year', 'relationship'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClassListFixtures();
        $this->schoolYear->forceFill(['is_active' => true])->save();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function buildCredentials(array $overrides = []): array
    {
        return ['username' => self::NUMBER, 'password' => self::PASSWORD, 'device_name' => self::DEVICE, ...$overrides];
    }

    private function createParentWithChild(): User
    {
        $parentUser = User::factory()->parentAccount()->create(['mobile_number' => self::NUMBER, 'password' => self::PASSWORD]);
        $student = $this->enrollStudent([], ['section_id' => $this->rizalSection->id])->student;
        $parentUser->children()->attach($student->id, ['relationship' => 'Mother']);

        return $parentUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFailedBody(string $field): array
    {
        return ['message' => self::FAILED_MESSAGE, 'errors' => [$field => [self::FAILED_MESSAGE]]];
    }

    public function test_parent_logs_in_with_the_mobile_number_as_username(): void
    {
        $this->createParentWithChild();

        $loginResponse = $this->postJson(self::LOGIN_URL, $this->buildCredentials())->assertOk();

        $this->assertSame(['token', 'user', 'student', 'must_change_password', 'children'], array_keys($loginResponse->json()));
        $this->assertNull($loginResponse->json('student'));
        $loginResponse->assertJsonPath('user.roles', [Role::PARENT])->assertJsonPath('must_change_password', false);
        $this->assertCount(1, $loginResponse->json('children'));
        $this->assertSame(self::MOBILE_CHILD_KEYS, array_keys($loginResponse->json('children.0')));
        $this->assertSame('Mother', $loginResponse->json('children.0.relationship'));
        $this->assertSame(['id' => $this->rizalSection->id, 'name' => self::RIZAL], $loginResponse->json('children.0.section'));
    }

    public function test_a_plus_63_formatted_number_logs_in(): void
    {
        $this->createParentWithChild();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['username' => '+63 917 000 0001']))->assertOk();
    }

    public function test_the_lrn_alias_logs_a_parent_in_and_errors_are_keyed_on_lrn(): void
    {
        $this->createParentWithChild();
        $aliasCredentials = ['lrn' => self::NUMBER, 'password' => self::PASSWORD, 'device_name' => self::DEVICE];

        $this->postJson(self::LOGIN_URL, $aliasCredentials)->assertOk();
        $this->postJson(self::LOGIN_URL, [...$aliasCredentials, 'password' => 'wrong-pass-9'])
            ->assertUnprocessable()
            ->assertExactJson($this->buildFailedBody('lrn'));
    }

    public function test_every_credential_failure_returns_the_identical_body(): void
    {
        $this->createParentWithChild();
        User::factory()->registrar()->create(['email' => 'staff@school.test', 'contact' => '09170000002', 'password' => self::PASSWORD]);
        $expectedBody = $this->buildFailedBody('username');

        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['password' => 'wrong-pass-9']))->assertUnprocessable()->assertExactJson($expectedBody);
        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['username' => '09170000099']))->assertUnprocessable()->assertExactJson($expectedBody);
        $this->postJson(self::LOGIN_URL, $this->buildCredentials(['username' => '09170000002']))->assertUnprocessable()->assertExactJson($expectedBody);
        $this->assertSame(0, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->count());
    }

    public function test_inactive_parent_gets_the_deactivated_notice_and_no_token(): void
    {
        $this->createParentWithChild()->forceFill(['is_active' => false])->save();

        $this->postJson(self::LOGIN_URL, $this->buildCredentials())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username' => AuthController::DEACTIVATED_MESSAGE]);

        $this->assertSame(0, PersonalAccessToken::where('name', '!=', self::TOKEN_NAME)->count());
    }

    public function test_student_logs_in_with_the_lrn_as_username_and_keeps_the_unchanged_body(): void
    {
        $student = $this->enrollStudent(['lrn' => self::LRN], ['section_id' => $this->rizalSection->id])->student;
        User::factory()->studentAccount($student)->create(['password' => self::PASSWORD]);

        $loginResponse = $this->postJson(self::LOGIN_URL, $this->buildCredentials(['username' => self::LRN]))->assertOk();

        $this->assertSame(['token', 'user', 'student', 'must_change_password'], array_keys($loginResponse->json()));
        $loginResponse->assertJsonPath('student.lrn', self::LRN);
    }

    public function test_a_missing_identifier_returns_422_on_both_fields(): void
    {
        $this->postJson(self::LOGIN_URL, ['password' => self::PASSWORD, 'device_name' => self::DEVICE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'lrn']);
    }

    public function test_a_parent_cannot_use_the_staff_login(): void
    {
        $parentUser = User::factory()->parentAccount()->create(['email' => 'ana@family.test', 'password' => self::PASSWORD]);

        $this->postJson(self::STAFF_LOGIN_URL, ['email' => $parentUser->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => self::FAILED_MESSAGE]);
    }
}
