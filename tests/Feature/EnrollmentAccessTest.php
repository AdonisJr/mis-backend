<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnrollmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENTS_URL = '/api/mis/enrollments';

    private const TOKEN_NAME = 'test';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private SchoolYear $schoolYear;

    private Section $firstSection;

    private Section $secondSection;

    private Enrollment $seededEnrollment;

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEnrollmentRoutes(): array
    {
        return [
            'list' => ['GET', self::ENROLLMENTS_URL],
            'enroll' => ['POST', self::ENROLLMENTS_URL],
            'move' => ['PUT', self::ENROLLMENTS_URL.'/%d/move'],
            'drop' => ['PUT', self::ENROLLMENTS_URL.'/%d/drop'],
            'transfer' => ['PUT', self::ENROLLMENTS_URL.'/%d/transfer'],
            'complete' => ['PUT', self::ENROLLMENTS_URL.'/%d/complete'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // the default end date is today, so it must fall after the fixed 2030 enrolled date
        $this->travelTo('2030-09-15');

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstSection = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'A']);
        $this->secondSection = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'B']);
        $this->seededEnrollment = Enrollment::factory()->create([
            'student_id' => $this->createStudent(1)->id,
            'section_id' => $this->firstSection->id,
            'enrolled_at' => '2030-06-10',
        ]);
    }

    #[DataProvider('provideEnrollmentRoutes')]
    public function test_guest_gets_401_on_enrollment_routes(string $method, string $uriPattern): void
    {
        $this->json($method, sprintf($uriPattern, $this->seededEnrollment->id), $this->buildBody())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEnrollmentsUnchanged();
    }

    #[DataProvider('provideEnrollmentRoutes')]
    public function test_user_without_role_gets_403_on_enrollment_routes(string $method, string $uriPattern): void
    {
        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->json($method, sprintf($uriPattern, $this->seededEnrollment->id), $this->buildBody())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEnrollmentsUnchanged();
    }

    public function test_admin_can_use_every_enrollment_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createTokenFor(User::factory()->admin()->create()));
    }

    public function test_registrar_can_use_every_enrollment_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createTokenFor(User::factory()->registrar()->create()));
    }

    private function assertEveryEndpointWorksFor(string $token): void
    {
        $this->withToken($token)
            ->getJson(self::ENROLLMENTS_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->assertJsonCount(1, 'enrollments');

        $this->withToken($token)
            ->putJson(self::ENROLLMENTS_URL.'/'.$this->seededEnrollment->id.'/move', ['section_id' => $this->secondSection->id])
            ->assertOk()
            ->assertJsonPath('enrollment.section_id', $this->secondSection->id);

        foreach (['drop' => Enrollment::STATUS_DROPPED, 'transfer' => Enrollment::STATUS_TRANSFERRED, 'complete' => Enrollment::STATUS_COMPLETED] as $action => $closedStatus) {
            $this->assertActionClosesNewEnrollment($token, $action, $closedStatus);
        }
    }

    private function assertActionClosesNewEnrollment(string $token, string $action, string $closedStatus): void
    {
        $student = $this->createStudent(Student::count() + 1);

        $enrollResponse = $this->withToken($token)
            ->postJson(self::ENROLLMENTS_URL, ['student_id' => $student->id, 'section_id' => $this->firstSection->id])
            ->assertCreated();

        $this->withToken($token)
            ->putJson(self::ENROLLMENTS_URL.'/'.$enrollResponse->json('enrollment.id').'/'.$action, [])
            ->assertOk()
            ->assertJsonPath('enrollment.status', $closedStatus);
    }

    private function assertEnrollmentsUnchanged(): void
    {
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $this->seededEnrollment->fresh()->status);
        $this->assertSame($this->firstSection->id, $this->seededEnrollment->fresh()->section_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBody(): array
    {
        return ['student_id' => $this->seededEnrollment->student_id, 'section_id' => $this->secondSection->id, 'school_year_id' => $this->schoolYear->id];
    }

    private function createStudent(int $number): Student
    {
        return Student::factory()->create(['lrn' => sprintf('13651234%04d', $number)]);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
