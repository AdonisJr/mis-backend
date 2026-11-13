<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectionRolloverAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private SchoolYear $schoolYear;

    private Term $firstTerm;

    private Term $secondTerm;

    private Section $section;

    private Enrollment $seededEnrollment;

    /**
     * @return array<string, array{string}>
     */
    public static function provideRolloverRoutes(): array
    {
        return ['preview' => ['preview'], 'rollover' => ['rollover'], 'complete' => ['complete']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-09-15');

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31']);
        $this->secondTerm = Term::factory()->create(['school_year_id' => $this->schoolYear->id, 'term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31']);
        $this->section = Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'STEM 11-A']);
        $this->seededEnrollment = Enrollment::factory()->create([
            'student_id' => Student::factory()->create(['lrn' => '136512340001'])->id,
            'section_id' => $this->section->id,
            'term_id' => $this->firstTerm->id,
            'enrolled_at' => '2030-08-01',
        ]);
    }

    #[DataProvider('provideRolloverRoutes')]
    public function test_guest_gets_401_on_rollover_routes(string $routeName): void
    {
        $this->callRoute($routeName, null)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertEnrollmentsUnchanged();
    }

    #[DataProvider('provideRolloverRoutes')]
    public function test_user_without_role_gets_403_on_rollover_routes(string $routeName): void
    {
        $this->callRoute($routeName, $this->createTokenFor(User::factory()->create()))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertEnrollmentsUnchanged();
    }

    public function test_admin_can_use_every_rollover_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createTokenFor(User::factory()->admin()->create()));
    }

    public function test_registrar_can_use_every_rollover_endpoint(): void
    {
        $this->assertEveryEndpointWorksFor($this->createTokenFor(User::factory()->registrar()->create()));
    }

    private function assertEveryEndpointWorksFor(string $token): void
    {
        $this->callRoute('preview', $token)->assertOk()->assertJsonCount(1, 'enrollments');
        $this->callRoute('rollover', $token)->assertOk()->assertJsonPath('copied_count', 1);
        $this->callRoute('complete', $token)->assertOk()->assertJsonPath('completed_count', 1);
    }

    private function callRoute(string $routeName, ?string $token): TestResponse
    {
        $sectionUrl = self::SECTIONS_URL.'/'.$this->section->id;
        $request = $token === null ? $this : $this->withToken($token);

        return match ($routeName) {
            'preview' => $request->getJson($sectionUrl.'/rollover-preview?term_id='.$this->firstTerm->id),
            'rollover' => $request->postJson($sectionUrl.'/rollover', ['term_id' => $this->firstTerm->id, 'enrollment_ids' => [$this->seededEnrollment->id]]),
            default => $request->putJson($sectionUrl.'/complete', ['term_id' => $this->secondTerm->id]),
        };
    }

    private function assertEnrollmentsUnchanged(): void
    {
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $this->seededEnrollment->fresh()->status);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
