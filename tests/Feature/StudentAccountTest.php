<?php

namespace Tests\Feature;

use App\Http\Controllers\StudentAccountController;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class StudentAccountTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const LRN = '100000000001';

    private const MOBILE_LOGIN_URL = '/api/mis/mobile/login';

    private const MOBILE_ME_URL = '/api/mis/mobile/me';

    private const PASSWORD_ALPHABET_PATTERN = '/^[ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789]{10}$/';

    private const ACCOUNT_KEYS = ['status', 'must_change_password', 'is_active'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    private function buildAccountUrl(Student $student, string $suffix = ''): string
    {
        return '/api/mis/students/'.$student->id.'/mobile-account'.$suffix;
    }

    private function createStudent(): Student
    {
        return Student::factory()->create(['lrn' => self::LRN]);
    }

    private function createAccount(Student $student): string
    {
        return $this->withToken($this->adminToken)->postJson($this->buildAccountUrl($student))->assertCreated()->json('temporary_password');
    }

    private function loginAsStudent(string $password): string
    {
        return $this->postJson(self::MOBILE_LOGIN_URL, ['lrn' => self::LRN, 'password' => $password, 'device_name' => 'test-phone'])->assertOk()->json('token');
    }

    public function test_show_returns_none_for_a_student_without_an_account(): void
    {
        $student = $this->createStudent();

        $this->withToken($this->adminToken)->getJson($this->buildAccountUrl($student))
            ->assertOk()
            ->assertExactJson(['account' => ['status' => Student::ACCOUNT_STATUS_NONE, 'must_change_password' => false, 'is_active' => false]]);
    }

    public function test_create_returns_account_with_a_one_time_temporary_password(): void
    {
        $student = Student::factory()->create(['lrn' => self::LRN, 'first_name' => 'Ligaya', 'middle_name' => null, 'last_name' => 'Quezon']);

        $createResponse = $this->withToken($this->adminToken)->postJson($this->buildAccountUrl($student))
            ->assertCreated()
            ->assertJsonPath('account.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD)
            ->assertJsonPath('account.is_active', true)
            ->assertJsonPath('account.must_change_password', true);
        $plainPassword = $createResponse->json('temporary_password');

        $this->assertSame(['account', 'temporary_password'], array_keys($createResponse->json()));
        $this->assertSame(self::ACCOUNT_KEYS, array_keys($createResponse->json('account')));
        $this->assertMatchesRegularExpression(self::PASSWORD_ALPHABET_PATTERN, $plainPassword);
        $accountUser = $student->refresh()->user;
        $this->assertNull($accountUser->email);
        $this->assertSame('Ligaya Quezon', $accountUser->name);
        $this->assertSame([Role::STUDENT], $accountUser->roles()->pluck('name')->all());
        $this->assertNotSame($plainPassword, $accountUser->password);
        $this->assertTrue(Hash::check($plainPassword, $accountUser->password));
    }

    public function test_created_account_logs_in_on_the_mobile_login(): void
    {
        $plainPassword = $this->createAccount($this->createStudent());

        $this->postJson(self::MOBILE_LOGIN_URL, ['lrn' => self::LRN, 'password' => $plainPassword, 'device_name' => 'test-phone'])
            ->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_create_returns_422_when_the_student_already_has_an_account(): void
    {
        $student = $this->createStudent();
        $this->createAccount($student);

        $this->withToken($this->adminToken)->postJson($this->buildAccountUrl($student))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account' => StudentAccountController::ACCOUNT_EXISTS_MESSAGE]);

        $this->assertSame(2, User::count());
    }

    public function test_show_never_returns_the_password(): void
    {
        $student = $this->createStudent();
        $this->createAccount($student);

        $showResponse = $this->withToken($this->adminToken)->getJson($this->buildAccountUrl($student))->assertOk();

        $this->assertSame(['account'], array_keys($showResponse->json()));
        $this->assertSame(self::ACCOUNT_KEYS, array_keys($showResponse->json('account')));
    }

    public function test_reset_replaces_the_password_and_revokes_every_token(): void
    {
        $student = $this->createStudent();
        $oldPassword = $this->createAccount($student);
        $oldToken = $this->loginAsStudent($oldPassword);

        $resetResponse = $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($student, '/password'))
            ->assertOk()
            ->assertJsonPath('account.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD);
        $newPassword = $resetResponse->json('temporary_password');

        $this->assertSame(['account', 'temporary_password'], array_keys($resetResponse->json()));
        $this->assertMatchesRegularExpression(self::PASSWORD_ALPHABET_PATTERN, $newPassword);
        $accountUser = $student->refresh()->user;
        $this->assertTrue(Hash::check($newPassword, $accountUser->password));
        $this->assertFalse(Hash::check($oldPassword, $accountUser->password));
        $this->assertTrue($accountUser->must_change_password);
        $this->assertSame(0, $accountUser->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson(self::MOBILE_ME_URL)->assertUnauthorized();
    }

    public function test_reset_without_an_account_returns_422(): void
    {
        $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($this->createStudent(), '/password'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account' => StudentAccountController::NO_ACCOUNT_MESSAGE]);
    }

    public function test_deactivate_marks_the_account_inactive_and_revokes_every_token(): void
    {
        $student = $this->createStudent();
        $studentToken = $this->loginAsStudent($this->activateAccount($student));

        $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($student, '/deactivate'))
            ->assertOk()
            ->assertExactJson(['account' => ['status' => Student::ACCOUNT_STATUS_INACTIVE, 'must_change_password' => false, 'is_active' => false]]);

        $this->assertSame(0, $student->refresh()->user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($studentToken)->getJson(self::MOBILE_ME_URL)->assertUnauthorized();
    }

    public function test_reactivate_restores_the_flag_based_status(): void
    {
        $student = $this->createStudent();
        $this->createAccount($student);
        $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($student, '/deactivate'))->assertOk();

        $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($student, '/reactivate'))
            ->assertOk()
            ->assertJsonPath('account.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD)
            ->assertJsonPath('account.is_active', true);
    }

    public function test_deactivate_and_reactivate_without_an_account_return_422(): void
    {
        $student = $this->createStudent();

        foreach (['/deactivate', '/reactivate'] as $actionSuffix) {
            $this->withToken($this->adminToken)->putJson($this->buildAccountUrl($student, $actionSuffix))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['account' => StudentAccountController::NO_ACCOUNT_MESSAGE]);
        }
    }

    public function test_missing_student_returns_404_on_every_account_route(): void
    {
        $missingUrl = '/api/mis/students/'.self::MISSING_ID.'/mobile-account';

        $this->withToken($this->adminToken)->getJson($missingUrl)->assertNotFound();
        $this->withToken($this->adminToken)->postJson($missingUrl)->assertNotFound();

        foreach (['/password', '/deactivate', '/reactivate'] as $actionSuffix) {
            $this->withToken($this->adminToken)->putJson($missingUrl.$actionSuffix)->assertNotFound();
        }
    }

    public function test_registrar_can_create_an_account(): void
    {
        $this->withToken($this->createRegistrarToken())->postJson($this->buildAccountUrl($this->createStudent()))
            ->assertCreated()
            ->assertJsonPath('account.status', Student::ACCOUNT_STATUS_MUST_CHANGE_PASSWORD);
    }

    /**
     * Creates the account and clears the must-change flag so the login body is a plain active session.
     */
    private function activateAccount(Student $student): string
    {
        $plainPassword = $this->createAccount($student);
        $student->refresh()->user->forceFill(['must_change_password' => false])->save();

        return $plainPassword;
    }
}
