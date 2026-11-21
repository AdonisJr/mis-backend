<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Support\PaymentHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithPaymentHolds;
use Tests\TestCase;

class EnrollmentHoldGuardTest extends TestCase
{
    use InteractsWithPaymentHolds;
    use RefreshDatabase;

    private const ENROLLMENTS_URL = '/api/mis/enrollments';

    private const SECTIONS_URL = '/api/mis/sections';

    private const OWED = '1500.00';

    private const FIRST_TERM = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_TERM = ['term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31'];

    /**
     * @return array<string, array{string}>
     */
    public static function provideClosingActions(): array
    {
        return ['drop' => ['drop'], 'transfer' => ['transfer'], 'complete' => ['complete']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpHoldFixtures();
    }

    public function test_prior_year_balance_blocks_enrollment(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => $this->buildHoldMessage(['2029-2030 (balance 1500.00)']),
                'errors' => [EnrollmentController::HOLD_ERROR_KEY => [$this->buildHoldMessage(['2029-2030 (balance 1500.00)'])]],
            ]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_hold_message_lists_each_uncovered_year(): void
    {
        $this->createYearAssessment($this->year2028, '250.00');
        $this->createYearAssessment($this->year2029, self::OWED);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())
            ->assertUnprocessable()
            ->assertJsonValidationErrors([EnrollmentController::HOLD_ERROR_KEY => $this->buildHoldMessage(['2028-2029 (balance 250.00)', '2029-2030 (balance 1500.00)'])]);

        $this->createNote($this->year2029);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())
            ->assertUnprocessable()
            ->assertJsonValidationErrors([EnrollmentController::HOLD_ERROR_KEY => $this->buildHoldMessage(['2028-2029 (balance 250.00)'])]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_active_note_allows_enrollment(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createNote($this->year2029);
        $section = $this->createTargetSection();

        $this->postEnrollment($this->adminToken, $section)->assertCreated();

        $this->assertDatabaseHas('enrollments', ['student_id' => $this->debtor->id, 'section_id' => $section->id]);
    }

    public function test_expired_note_blocks_enrollment_again(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->createNote($this->year2029, ['promise_date' => '2030-07-10']);
        $section = $this->createTargetSection();

        $this->travelTo(Carbon::parse('2030-07-10 23:59:59', self::MANILA));
        $this->postEnrollment($this->adminToken, $section)->assertCreated();
        Enrollment::query()->delete();

        $this->travelTo(Carbon::parse('2030-07-11 00:00:00', self::MANILA));
        $this->postEnrollment($this->adminToken, $section)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([EnrollmentController::HOLD_ERROR_KEY]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_registrar_is_blocked_too(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);

        $this->postEnrollment($this->createRegistrarToken(), $this->createTargetSection())
            ->assertUnprocessable()
            ->assertJsonValidationErrors([EnrollmentController::HOLD_ERROR_KEY => $this->buildHoldMessage(['2029-2030 (balance 1500.00)'])]);

        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_same_year_balance_does_not_block_reenrollment(): void
    {
        $this->createYearAssessment($this->schoolYear, self::OWED);
        $this->enrollStudent($this->debtor, GradeLevel::STAGE_JHS, Enrollment::STATUS_DROPPED);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())->assertCreated();
    }

    public function test_on_hold_student_already_enrolled_in_year_can_reenroll(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $this->enrollStudent($this->debtor, GradeLevel::STAGE_JHS, Enrollment::STATUS_DROPPED);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())->assertCreated();

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_later_year_balance_does_not_block(): void
    {
        $this->createYearAssessment(SchoolYear::factory()->create(self::SCHOOL_YEAR_2031), self::OWED);

        $this->postEnrollment($this->adminToken, $this->createTargetSection())->assertCreated();
    }

    public function test_hold_is_reported_before_capacity_errors(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $fullSection = $this->createTargetSection(['capacity' => 1]);
        Enrollment::factory()->create(['student_id' => Student::factory()->create()->id, 'section_id' => $fullSection->id]);

        $this->postEnrollment($this->adminToken, $fullSection)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([EnrollmentController::HOLD_ERROR_KEY])
            ->assertJsonMissingValidationErrors(['section_id', 'student_id']);
    }

    #[DataProvider('provideClosingActions')]
    public function test_closing_actions_ignore_holds(string $action): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $enrollment = $this->enrollStudent($this->debtor, GradeLevel::STAGE_JHS);

        $this->sendAs($this->adminToken, 'PUT', self::ENROLLMENTS_URL.'/'.$enrollment->id.'/'.$action)->assertOk();

        $this->assertDatabaseMissing('enrollments', ['id' => $enrollment->id, 'status' => Enrollment::STATUS_ENROLLED]);
    }

    public function test_move_ignores_holds(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $source = $this->enrollStudent($this->debtor, GradeLevel::STAGE_JHS);
        $targetSection = $this->createTargetSection();

        $this->sendAs($this->adminToken, 'PUT', self::ENROLLMENTS_URL.'/'.$source->id.'/move', ['section_id' => $targetSection->id])->assertOk();

        $this->assertSame(Enrollment::STATUS_MOVED, $source->fresh()->status);
    }

    public function test_section_complete_ignores_holds(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $section = $this->createTargetSection();
        Enrollment::factory()->create(['student_id' => $this->debtor->id, 'section_id' => $section->id]);

        $this->sendAs($this->adminToken, 'PUT', self::SECTIONS_URL.'/'.$section->id.'/complete')
            ->assertOk()
            ->assertExactJson(['completed_count' => 1]);
    }

    public function test_rollover_ignores_holds(): void
    {
        $this->createYearAssessment($this->year2029, self::OWED);
        $firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $section = Section::factory()->create(['school_year_id' => $this->schoolYear->id]);
        $source = Enrollment::factory()->create(['student_id' => $this->debtor->id, 'section_id' => $section->id, 'term_id' => $firstTerm->id]);

        $this->sendAs($this->adminToken, 'POST', self::SECTIONS_URL.'/'.$section->id.'/rollover', ['term_id' => $firstTerm->id, 'enrollment_ids' => [$source->id]])
            ->assertOk()
            ->assertExactJson(['copied_count' => 1, 'skipped' => []]);
    }

    /**
     * @param  list<string>  $unpaidYearLabels
     */
    private function buildHoldMessage(array $unpaidYearLabels): string
    {
        return sprintf(PaymentHold::HOLD_MESSAGE_FORMAT, implode(', ', $unpaidYearLabels));
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createTargetSection(array $sectionFields = []): Section
    {
        return Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, ...$sectionFields]);
    }

    private function postEnrollment(string $token, Section $section): TestResponse
    {
        return $this->sendAs($token, 'POST', self::ENROLLMENTS_URL, ['student_id' => $this->debtor->id, 'section_id' => $section->id]);
    }
}
