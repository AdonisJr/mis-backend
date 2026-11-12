<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const STUDENTS_URL = '/api/mis/students';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const NON_STAFF_ROLE = 'student';

    private const VALID_PAYLOAD = [
        'lrn' => '136512340001',
        'first_name' => 'Andrea',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'birth_date' => '2010-05-14',
        'gender' => 'female',
        'address' => 'Purok 3, Barangay Poblacion',
        'contact' => '09181234567',
        'guardian_name' => 'Maria Dela Cruz',
        'guardian_relationship' => 'Mother',
        'guardian_contact' => '09171234567',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideStudentRoutes(): array
    {
        return [
            'list' => ['GET', self::STUDENTS_URL],
            'create' => ['POST', self::STUDENTS_URL],
            'update' => ['PUT', self::STUDENTS_URL.'/%d'],
            'delete' => ['DELETE', self::STUDENTS_URL.'/%d'],
        ];
    }

    public function test_admin_can_use_every_student_endpoint(): void
    {
        $this->assertStaffCanUseEveryEndpoint($this->createTokenFor(User::factory()->admin()->create()));
    }

    public function test_registrar_can_use_every_student_endpoint(): void
    {
        $this->assertStaffCanUseEveryEndpoint($this->createTokenFor(User::factory()->registrar()->create()));
    }

    #[DataProvider('provideStudentRoutes')]
    public function test_guest_gets_401_on_student_routes(string $method, string $uriPattern): void
    {
        $student = Student::factory()->create();

        $this->json($method, sprintf($uriPattern, $student->id), self::VALID_PAYLOAD)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertStudentUnchanged($student);
    }

    #[DataProvider('provideStudentRoutes')]
    public function test_user_without_role_gets_403_on_student_routes(string $method, string $uriPattern): void
    {
        $student = Student::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->json($method, sprintf($uriPattern, $student->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertStudentUnchanged($student);
    }

    #[DataProvider('provideStudentRoutes')]
    public function test_user_with_non_staff_role_gets_403_on_student_routes(string $method, string $uriPattern): void
    {
        $student = Student::factory()->create();
        $nonStaffUser = User::factory()->create();
        $nonStaffUser->roles()->attach(Role::firstOrCreate(['name' => self::NON_STAFF_ROLE]));

        $this->withToken($this->createTokenFor($nonStaffUser))
            ->json($method, sprintf($uriPattern, $student->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertStudentUnchanged($student);
    }

    public function test_user_without_role_gets_403_for_missing_id(): void
    {
        $roleLessToken = $this->createTokenFor(User::factory()->create());

        $this->withToken($roleLessToken)
            ->putJson(self::STUDENTS_URL.'/'.self::MISSING_ID, self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->withToken($roleLessToken)
            ->deleteJson(self::STUDENTS_URL.'/'.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_registrar_gets_404_for_missing_id(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->deleteJson(self::STUDENTS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Student::class.'] '.self::MISSING_ID]);
    }

    public function test_student_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::STUDENTS_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_admin_and_registrar_see_the_same_list(): void
    {
        $student = Student::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create());

        $this->withToken($adminToken)
            ->getJson(self::STUDENTS_URL)
            ->assertOk()
            ->assertJsonPath('students.0.id', $student->id);

        // the resolved user is cached on the guard between requests in one test
        $this->app['auth']->forgetGuards();

        $this->withToken($registrarToken)
            ->getJson(self::STUDENTS_URL)
            ->assertOk()
            ->assertJsonPath('students.0.id', $student->id);
    }

    private function assertStaffCanUseEveryEndpoint(string $token): void
    {
        $this->withToken($token)
            ->getJson(self::STUDENTS_URL)
            ->assertOk();

        $createdStudentId = $this->withToken($token)
            ->postJson(self::STUDENTS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->json('student.id');

        $this->withToken($token)
            ->putJson(self::STUDENTS_URL.'/'.$createdStudentId, [...self::VALID_PAYLOAD, 'middle_name' => 'Bonifacio'])
            ->assertOk()
            ->assertJsonPath('student.middle_name', 'Bonifacio');

        $this->withToken($token)
            ->deleteJson(self::STUDENTS_URL.'/'.$createdStudentId)
            ->assertNoContent();

        $this->assertDatabaseCount('students', 0);
    }

    private function assertStudentUnchanged(Student $student): void
    {
        $this->assertDatabaseCount('students', 1);
        $this->assertSame($student->lrn, $student->fresh()->lrn);
        $this->assertSame($student->middle_name, $student->fresh()->middle_name);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
