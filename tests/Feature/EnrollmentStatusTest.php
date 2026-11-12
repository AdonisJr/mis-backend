<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnrollmentStatusTest extends TestCase
{
    use RefreshDatabase;

    private const ENROLLMENTS_URL = '/api/mis/enrollments';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ENROLLED_AT = '2030-08-01';

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2031 = ['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const FIRST_TERM = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_TERM = ['term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31'];

    private SchoolYear $schoolYear;

    private Term $firstTerm;

    private Term $secondTerm;

    private Student $student;

    private string $adminToken;

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideClosingActions(): array
    {
        return [
            'drop' => ['drop', Enrollment::STATUS_DROPPED],
            'transfer' => ['transfer', Enrollment::STATUS_TRANSFERRED],
            'complete' => ['complete', Enrollment::STATUS_COMPLETED],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAllActionsWithClosedStatus(): array
    {
        return [
            'move on dropped' => ['move', Enrollment::STATUS_DROPPED],
            'drop on moved' => ['drop', Enrollment::STATUS_MOVED],
            'transfer on completed' => ['transfer', Enrollment::STATUS_COMPLETED],
            'complete on transferred' => ['complete', Enrollment::STATUS_TRANSFERRED],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAllActions(): array
    {
        return ['move' => ['move'], 'drop' => ['drop'], 'transfer' => ['transfer'], 'complete' => ['complete']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // the default end date is today, so it must fall after the fixed 2030 enrolled dates
        $this->travelTo('2030-09-15');

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->secondTerm = Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->student = Student::factory()->create(['lrn' => '136512340001', 'first_name' => 'Andrea', 'last_name' => 'Dela Cruz']);
        $this->adminToken = $this->createTokenFor(User::factory()->admin()->create());
    }

    public function test_move_closes_source_and_opens_new_record_in_target(): void
    {
        $sourceSection = $this->createShsSection('STEM 11-A');
        $targetSection = $this->createShsSection('STEM 11-B', ['program_id' => $sourceSection->program_id]);
        $source = $this->createEnrollment($sourceSection, ['term_id' => $this->firstTerm->id]);

        $moveResponse = $this->putAction($source->id, 'move', ['section_id' => $targetSection->id, 'ended_at' => '2030-09-01', 'remarks' => 'Schedule conflict'])
            ->assertOk()
            ->assertJsonPath('enrollment.section_id', $targetSection->id)
            ->assertJsonPath('enrollment.term_id', $this->firstTerm->id)
            ->assertJsonPath('enrollment.status', Enrollment::STATUS_ENROLLED)
            ->assertJsonPath('enrollment.enrolled_at', '2030-09-01')
            ->assertJsonPath('enrollment.ended_at', null)
            ->assertJsonPath('enrollment.remarks', null)
            ->assertJsonPath('enrollment.section.name', 'STEM 11-B');

        $this->assertNotSame($source->id, $moveResponse->json('enrollment.id'));
        $reloadedSource = $source->fresh();
        $this->assertSame(Enrollment::STATUS_MOVED, $reloadedSource->status);
        $this->assertSame('2030-09-01', $reloadedSource->ended_at->toDateString());
        $this->assertSame('Schedule conflict', $reloadedSource->remarks);
        $this->assertSame($sourceSection->id, $reloadedSource->section_id);
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_move_jhs_keeps_null_term(): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));
        $targetSection = $this->createJhsSection('B');

        $this->putAction($source->id, 'move', ['section_id' => $targetSection->id])
            ->assertOk()
            ->assertJsonPath('enrollment.term_id', null)
            ->assertJsonPath('enrollment.term', null)
            ->assertJsonPath('enrollment.section_id', $targetSection->id);

        $this->assertSame(Enrollment::STATUS_MOVED, $source->fresh()->status);
    }

    public function test_move_to_other_grade_level_returns_422(): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));
        $gradeEightSection = $this->createJhsSection('B', ['grade_level_id' => GradeLevel::where('name', 'Grade 8')->value('id')]);

        $this->putAction($source->id, 'move', ['section_id' => $gradeEightSection->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::MOVE_TARGET_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
    }

    public function test_move_to_other_school_year_returns_422(): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $otherYearSection = Section::factory()->jhs()->create(['school_year_id' => $otherYear->id, 'name' => 'B']);

        $this->putAction($source->id, 'move', ['section_id' => $otherYearSection->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::MOVE_TARGET_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_move_to_other_program_same_grade_level_succeeds(): void
    {
        $sourceSection = $this->createShsSection('STEM 11-A');
        $otherProgramSection = $this->createShsSection('ABM 11-A', ['program_id' => Program::factory()->shs()->create()->id]);
        $source = $this->createEnrollment($sourceSection, ['term_id' => $this->firstTerm->id]);

        $this->putAction($source->id, 'move', ['section_id' => $otherProgramSection->id])
            ->assertOk()
            ->assertJsonPath('enrollment.section.program.id', $otherProgramSection->program_id);
    }

    public function test_move_to_same_section_returns_422(): void
    {
        $section = $this->createJhsSection('A');
        $source = $this->createEnrollment($section);

        $this->putAction($source->id, 'move', ['section_id' => $section->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::SAME_SECTION_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_move_into_full_section_returns_422_and_changes_nothing(): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));
        $fullSection = $this->createJhsSection('B', ['capacity' => 1]);
        $this->createEnrollment($fullSection, ['student_id' => Student::factory()->create()->id]);

        $this->putAction($source->id, 'move', ['section_id' => $fullSection->id, 'remarks' => 'Should roll back'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => EnrollmentController::SECTION_FULL_MESSAGE]);

        $reloadedSource = $source->fresh();
        $this->assertSame(Enrollment::STATUS_ENROLLED, $reloadedSource->status);
        $this->assertNull($reloadedSource->ended_at);
        $this->assertNull($reloadedSource->remarks);
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_move_capacity_ignores_closed_and_other_term(): void
    {
        $source = $this->createEnrollment($this->createShsSection('STEM 11-A'), ['term_id' => $this->firstTerm->id]);
        $targetSection = $this->createShsSection('STEM 11-B', ['capacity' => 1]);
        $this->createEnrollment($targetSection, ['student_id' => Student::factory()->create()->id, 'term_id' => $this->firstTerm->id, 'status' => Enrollment::STATUS_DROPPED, 'ended_at' => self::ENROLLED_AT]);
        $this->createEnrollment($targetSection, ['student_id' => Student::factory()->create()->id, 'term_id' => $this->secondTerm->id]);

        $this->putAction($source->id, 'move', ['section_id' => $targetSection->id])->assertOk();

        $this->assertSame(Enrollment::STATUS_MOVED, $source->fresh()->status);
    }

    public function test_move_ended_at_edges(): void
    {
        $sourceSection = $this->createJhsSection('A');
        $targetSection = $this->createJhsSection('B');
        $source = $this->createEnrollment($sourceSection);

        $this->putAction($source->id, 'move', ['section_id' => $targetSection->id, 'ended_at' => '2030-07-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at' => EnrollmentController::ENDED_BEFORE_ENROLLED_MESSAGE]);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertDatabaseCount('enrollments', 1);

        $this->putAction($source->id, 'move', ['section_id' => $targetSection->id, 'ended_at' => self::ENROLLED_AT])
            ->assertOk()
            ->assertJsonPath('enrollment.enrolled_at', self::ENROLLED_AT);
        $this->assertSame(self::ENROLLED_AT, $source->fresh()->ended_at->toDateString());
    }

    #[DataProvider('provideClosingActions')]
    public function test_closing_action_defaults_ended_at_to_today(string $action, string $closedStatus): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));

        $this->putAction($source->id, $action, [])
            ->assertOk()
            ->assertJsonPath('enrollment.id', $source->id)
            ->assertJsonPath('enrollment.status', $closedStatus)
            ->assertJsonPath('enrollment.ended_at', '2030-09-15');

        $this->assertSame($closedStatus, $source->fresh()->status);
        $this->assertSame('2030-09-15', $source->fresh()->ended_at->toDateString());
        $this->assertDatabaseCount('enrollments', 1);
    }

    #[DataProvider('provideClosingActions')]
    public function test_closing_action_stores_given_ended_at_and_remarks(string $action, string $closedStatus): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'), ['remarks' => 'Kept remark']);

        $this->putAction($source->id, $action, [])->assertOk();
        $this->assertSame('Kept remark', $source->fresh()->remarks);

        $secondSource = $this->createEnrollment($this->createJhsSection('B'), ['student_id' => Student::factory()->create()->id, 'remarks' => 'Old remark']);

        $this->putAction($secondSource->id, $action, ['ended_at' => '2030-10-05', 'remarks' => 'Closing note'])
            ->assertOk()
            ->assertJsonPath('enrollment.status', $closedStatus)
            ->assertJsonPath('enrollment.ended_at', '2030-10-05')
            ->assertJsonPath('enrollment.remarks', 'Closing note');
    }

    #[DataProvider('provideClosingActions')]
    public function test_closing_action_ended_at_edges(string $action, string $closedStatus): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));

        $this->putAction($source->id, $action, ['ended_at' => '2030-07-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at' => EnrollmentController::ENDED_BEFORE_ENROLLED_MESSAGE]);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertNull($source->fresh()->ended_at);

        $this->putAction($source->id, $action, ['ended_at' => self::ENROLLED_AT])
            ->assertOk()
            ->assertJsonPath('enrollment.status', $closedStatus);
    }

    #[DataProvider('provideAllActionsWithClosedStatus')]
    public function test_any_action_on_closed_record_returns_422(string $action, string $closedStatus): void
    {
        $section = $this->createJhsSection('A');
        $targetSection = $this->createJhsSection('B');
        $source = $this->createEnrollment($section, ['status' => $closedStatus, 'ended_at' => '2030-09-01']);

        $this->putAction($source->id, $action, ['section_id' => $targetSection->id, 'remarks' => 'Too late'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => Enrollment::CLOSED_MESSAGE]);

        $reloadedSource = $source->fresh();
        $this->assertSame($closedStatus, $reloadedSource->status);
        $this->assertNull($reloadedSource->remarks);
        $this->assertDatabaseCount('enrollments', 1);
    }

    #[DataProvider('provideAllActions')]
    public function test_actions_on_missing_enrollment_return_404(string $action): void
    {
        config(['app.debug' => false]);
        $targetSection = $this->createJhsSection('B');

        $this->putAction(self::MISSING_ID, $action, ['section_id' => $targetSection->id])
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Enrollment::class.'] '.self::MISSING_ID]);
    }

    public function test_move_validates_body_before_lookup(): void
    {
        $this->putAction(self::MISSING_ID, 'move', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);
        $this->putAction(self::MISSING_ID, 'drop', ['ended_at' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at']);
    }

    public function test_enrollment_has_no_update_or_delete_route(): void
    {
        $source = $this->createEnrollment($this->createJhsSection('A'));

        $this->withToken($this->adminToken)->putJson(self::ENROLLMENTS_URL.'/'.$source->id, ['remarks' => 'Edited'])->assertNotFound();
        $this->withToken($this->adminToken)->deleteJson(self::ENROLLMENTS_URL.'/'.$source->id)->assertNotFound();

        $this->assertNull($source->fresh()->remarks);
        $this->assertDatabaseCount('enrollments', 1);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putAction(int $enrollmentId, string $action, array $body): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::ENROLLMENTS_URL.'/'.$enrollmentId.'/'.$action, $body);
    }

    /**
     * @param  array<string, mixed>  $enrollmentFields
     */
    private function createEnrollment(Section $section, array $enrollmentFields = []): Enrollment
    {
        return Enrollment::factory()->create([
            'student_id' => $this->student->id,
            'section_id' => $section->id,
            'enrolled_at' => self::ENROLLED_AT,
            ...$enrollmentFields,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createJhsSection(string $name, array $sectionFields = []): Section
    {
        return Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name, ...$sectionFields]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createShsSection(string $name, array $sectionFields = []): Section
    {
        return Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name, ...$sectionFields]);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
