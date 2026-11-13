<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\SectionRolloverController;
use App\Http\Requests\PreviewSectionRolloverRequest;
use App\Http\Requests\StoreEnrollmentRequest;
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

class SectionRolloverTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ENROLLED_AT = '2030-08-01';

    private const TODAY = '2030-09-15';

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2031 = ['name' => '2031-2032', 'start_date' => '2031-06-01', 'end_date' => '2032-03-31', 'term_type' => SchoolYear::TERM_TYPE_TRIMESTER];

    private const FIRST_TERM = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_TERM = ['term_number' => 2, 'start_date' => '2030-11-15', 'end_date' => '2031-03-31'];

    private SchoolYear $schoolYear;

    private Term $firstTerm;

    private Term $secondTerm;

    private Student $andrea;

    private Student $miguel;

    private Student $bea;

    private Student $carlo;

    private string $adminToken;

    /**
     * @return array<string, array{string}>
     */
    public static function provideForeignSelections(): array
    {
        return [
            'closed record' => ['closed'],
            'other section' => ['other section'],
            'other term' => ['other term'],
            'missing id' => ['missing'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::TODAY);

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->secondTerm = Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->andrea = Student::factory()->create(['lrn' => '136512340001', 'first_name' => 'Andrea', 'last_name' => 'Dela Cruz']);
        $this->miguel = Student::factory()->create(['lrn' => '136512340002', 'first_name' => 'Miguel', 'last_name' => 'Bautista']);
        $this->bea = Student::factory()->create(['lrn' => '136512340003', 'first_name' => 'Bea', 'last_name' => 'Reyes']);
        $this->carlo = Student::factory()->create(['lrn' => '136512340004', 'first_name' => 'Carlo', 'last_name' => 'Abad']);
        $this->adminToken = User::factory()->admin()->create()->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    public function test_rollover_completes_sources_and_opens_records_in_next_term(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm, ['remarks' => 'Old note']);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm);

        $this->postRollover($section->id, $this->buildBody([$andreaSource, $miguelSource]))
            ->assertOk()
            ->assertExactJson(['copied_count' => 2, 'skipped' => []]);

        foreach ([$andreaSource, $miguelSource] as $source) {
            $reloadedSource = $source->fresh();
            $this->assertSame(Enrollment::STATUS_COMPLETED, $reloadedSource->status);
            $this->assertSame(self::TODAY, $reloadedSource->ended_at->toDateString());
        }

        $this->assertDatabaseCount('enrollments', 4);
        $newRows = Enrollment::where('term_id', $this->secondTerm->id)->get();
        $this->assertCount(2, $newRows);
        foreach ($newRows as $newRow) {
            $this->assertSame(Enrollment::STATUS_ENROLLED, $newRow->status);
            $this->assertSame($section->id, $newRow->section_id);
            $this->assertSame(self::TODAY, $newRow->enrolled_at->toDateString());
            $this->assertNull($newRow->ended_at);
            $this->assertNull($newRow->remarks);
        }
    }

    public function test_rollover_stores_given_ended_at_and_remarks(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm, ['remarks' => 'Old note']);

        $this->postRollover($section->id, [...$this->buildBody([$source]), 'ended_at' => '2030-10-31', 'remarks' => 'Term finished'])->assertOk();

        $reloadedSource = $source->fresh();
        $this->assertSame('2030-10-31', $reloadedSource->ended_at->toDateString());
        $this->assertSame('Term finished', $reloadedSource->remarks);
        $newRow = Enrollment::where('term_id', $this->secondTerm->id)->firstOrFail();
        $this->assertSame('2030-10-31', $newRow->enrolled_at->toDateString());
        $this->assertNull($newRow->remarks);
    }

    public function test_rollover_keeps_source_remarks_when_none_given(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm, ['remarks' => 'Old note']);

        $this->postRollover($section->id, $this->buildBody([$source]))->assertOk();

        $this->assertSame('Old note', $source->fresh()->remarks);
    }

    public function test_rollover_skips_students_already_enrolled_in_next_term(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->andrea, $otherSection, $this->secondTerm);

        $rolloverResponse = $this->postRollover($section->id, $this->buildBody([$andreaSource, $miguelSource]))
            ->assertOk()
            ->assertJsonPath('copied_count', 1)
            ->assertJsonCount(1, 'skipped')
            ->assertJsonPath('skipped.0.id', $andreaSource->id)
            ->assertJsonPath('skipped.0.status', Enrollment::STATUS_ENROLLED)
            ->assertJsonPath('skipped.0.is_enrolled_in_target', true)
            ->assertJsonPath('skipped.0.skip_reason', EnrollmentController::ALREADY_ENROLLED_MESSAGE);

        $this->assertCount(15, $rolloverResponse->json('skipped.0'));
        $this->assertSame(Enrollment::STATUS_ENROLLED, $andreaSource->fresh()->status);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $miguelSource->fresh()->status);
        $this->assertDatabaseCount('enrollments', 4);
    }

    public function test_rollover_with_every_student_skipped_returns_zero_copied(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $this->createEnrollment($this->andrea, $otherSection, $this->secondTerm);

        $this->postRollover($section->id, $this->buildBody([$source]))
            ->assertOk()
            ->assertJsonPath('copied_count', 0)
            ->assertJsonCount(1, 'skipped');

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_rollover_over_capacity_returns_422_and_changes_nothing(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 2]);
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->secondTerm);

        $this->postRollover($section->id, $this->buildBody([$andreaSource, $miguelSource]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id' => SectionRolloverController::NOT_ENOUGH_SEATS_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $andreaSource->fresh()->status);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $miguelSource->fresh()->status);
        $this->assertDatabaseCount('enrollments', 3);
    }

    public function test_rollover_copying_exactly_the_seats_left_passes(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 2]);
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->secondTerm);

        $this->postRollover($section->id, $this->buildBody([$source]))->assertOk()->assertJsonPath('copied_count', 1);
    }

    public function test_rollover_capacity_ignores_skipped_students(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 2]);
        $otherSection = $this->createShsSection('STEM 11-B');
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->bea, $section, $this->secondTerm);
        $this->createEnrollment($this->andrea, $otherSection, $this->secondTerm);

        $this->postRollover($section->id, $this->buildBody([$andreaSource, $miguelSource]))
            ->assertOk()
            ->assertJsonPath('copied_count', 1)
            ->assertJsonCount(1, 'skipped');
    }

    public function test_rollover_capacity_ignores_closed_records_in_next_term(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 2]);
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->secondTerm, ['status' => Enrollment::STATUS_COMPLETED, 'ended_at' => '2030-09-01']);
        $this->createEnrollment($this->bea, $section, $this->secondTerm, ['status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-09-01']);

        $this->postRollover($section->id, $this->buildBody([$andreaSource, $miguelSource]))->assertOk()->assertJsonPath('copied_count', 2);
    }

    public function test_rollover_rejects_jhs_section(): void
    {
        $jhsSection = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'A']);
        $source = $this->createEnrollment($this->andrea, $jhsSection, null);

        $this->postRollover($jhsSection->id, ['term_id' => $this->firstTerm->id, 'enrollment_ids' => [$source->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => PreviewSectionRolloverRequest::JHS_ROLLOVER_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
    }

    public function test_rollover_from_last_term_returns_422(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->secondTerm);

        $this->postRollover($section->id, ['term_id' => $this->secondTerm->id, 'enrollment_ids' => [$source->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => SectionRolloverController::LAST_TERM_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_rollover_with_missing_next_term_returns_422(): void
    {
        $trimesterYear = SchoolYear::factory()->trimester()->create(self::SCHOOL_YEAR_2031);
        $trimesterFirstTerm = Term::factory()->create(['school_year_id' => $trimesterYear->id, 'term_number' => 1, 'start_date' => '2031-06-01', 'end_date' => '2031-09-30']);
        Term::factory()->create(['school_year_id' => $trimesterYear->id, 'term_number' => 3, 'start_date' => '2032-01-01', 'end_date' => '2032-03-31']);
        $section = $this->createShsSection('STEM 11-A', ['school_year_id' => $trimesterYear->id]);
        $source = $this->createEnrollment($this->andrea, $section, $trimesterFirstTerm, ['enrolled_at' => '2031-06-10']);

        $this->postRollover($section->id, ['term_id' => $trimesterFirstTerm->id, 'enrollment_ids' => [$source->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => SectionRolloverController::NEXT_TERM_MISSING_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_rollover_rejects_term_of_other_school_year(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $otherYear = SchoolYear::factory()->create(['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31']);
        $otherTerm = Term::factory()->create(['school_year_id' => $otherYear->id, 'term_number' => 1, 'start_date' => '2032-06-01', 'end_date' => '2032-10-31']);

        $this->postRollover($section->id, ['term_id' => $otherTerm->id, 'enrollment_ids' => [$source->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_YEAR_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
    }

    #[DataProvider('provideForeignSelections')]
    public function test_rollover_rejects_selection_outside_section_and_term(string $foreignKind): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $validSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $foreignId = match ($foreignKind) {
            'closed' => $this->createEnrollment($this->miguel, $section, $this->firstTerm, ['status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-08-20'])->id,
            'other section' => $this->createEnrollment($this->miguel, $otherSection, $this->firstTerm)->id,
            'other term' => $this->createEnrollment($this->miguel, $section, $this->secondTerm)->id,
            default => self::MISSING_ID,
        };
        $rowCount = Enrollment::count();

        $this->postRollover($section->id, ['term_id' => $this->firstTerm->id, 'enrollment_ids' => [$validSource->id, $foreignId]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_ids' => SectionRolloverController::INVALID_SELECTION_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $validSource->fresh()->status);
        $this->assertDatabaseCount('enrollments', $rowCount);
    }

    public function test_second_rollover_of_same_records_returns_422(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);

        $this->postRollover($section->id, $this->buildBody([$source]))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postRollover($section->id, $this->buildBody([$source]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_ids' => SectionRolloverController::INVALID_SELECTION_MESSAGE]);

        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_rollover_rejects_invalid_body(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $termId = $this->firstTerm->id;
        $invalidBodies = [
            'term_id' => ['enrollment_ids' => [$source->id]],
            'enrollment_ids' => ['term_id' => $termId],
            'enrollment_ids (empty)' => ['term_id' => $termId, 'enrollment_ids' => []],
            'enrollment_ids (string)' => ['term_id' => $termId, 'enrollment_ids' => 'abc'],
            'enrollment_ids (object)' => ['term_id' => $termId, 'enrollment_ids' => (object) []],
            'enrollment_ids (keyed object)' => ['term_id' => $termId, 'enrollment_ids' => ['first' => $source->id]],
            'enrollment_ids.1' => ['term_id' => $termId, 'enrollment_ids' => [$source->id, $source->id]],
            'enrollment_ids.0 (leading zero)' => ['term_id' => $termId, 'enrollment_ids' => ['05']],
            'enrollment_ids.0 (boolean)' => ['term_id' => $termId, 'enrollment_ids' => [true]],
            'enrollment_ids.0 (nested)' => ['term_id' => $termId, 'enrollment_ids' => [[$source->id]]],
            'ended_at' => [...$this->buildBody([$source]), 'ended_at' => '15/09/2030'],
            'remarks' => [...$this->buildBody([$source]), 'remarks' => str_repeat('a', 1001)],
        ];

        foreach ($invalidBodies as $rejectedField => $invalidBody) {
            $this->postRollover($section->id, $invalidBody)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([explode(' ', $rejectedField)[0]]);
        }

        $this->assertSame(Enrollment::STATUS_ENROLLED, $source->fresh()->status);
        $this->assertDatabaseCount('enrollments', 1);

        $this->postRollover($section->id, [...$this->buildBody([$source]), 'remarks' => str_repeat('a', 1000)])->assertOk();
    }

    public function test_rollover_ended_at_equal_to_enrolled_at_passes(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $source = $this->createEnrollment($this->andrea, $section, $this->firstTerm);

        $this->postRollover($section->id, [...$this->buildBody([$source]), 'ended_at' => self::ENROLLED_AT])->assertOk();

        $this->assertSame(self::ENROLLED_AT, $source->fresh()->ended_at->toDateString());
    }

    public function test_rollover_ended_at_before_an_enrolled_at_returns_422_and_changes_nothing(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $andreaSource = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelSource = $this->createEnrollment($this->miguel, $section, $this->firstTerm, ['enrolled_at' => '2030-09-01']);

        $this->postRollover($section->id, [...$this->buildBody([$andreaSource, $miguelSource]), 'ended_at' => '2030-08-15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at' => EnrollmentController::ENDED_BEFORE_ENROLLED_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $andreaSource->fresh()->status);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $miguelSource->fresh()->status);
        $this->assertDatabaseCount('enrollments', 2);
    }

    public function test_rollover_of_missing_section_returns_404(): void
    {
        $this->postRollover(self::MISSING_ID, ['term_id' => $this->firstTerm->id, 'enrollment_ids' => [1]])->assertNotFound();
    }

    public function test_rollover_body_errors_come_before_404(): void
    {
        $this->postRollover(self::MISSING_ID, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id', 'enrollment_ids']);
    }

    public function test_rollover_works_for_college_section(): void
    {
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'BSIT 1-A']);
        $source = $this->createEnrollment($this->andrea, $collegeSection, $this->firstTerm);

        $this->postRollover($collegeSection->id, $this->buildBody([$source]))->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertDatabaseHas('enrollments', ['section_id' => $collegeSection->id, 'term_id' => $this->secondTerm->id, 'status' => Enrollment::STATUS_ENROLLED]);
    }

    /**
     * @param  list<Enrollment>  $sources
     * @return array<string, mixed>
     */
    private function buildBody(array $sources): array
    {
        return ['term_id' => $this->firstTerm->id, 'enrollment_ids' => array_map(fn (Enrollment $source) => $source->id, $sources)];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postRollover(int $sectionId, array $body): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::SECTIONS_URL.'/'.$sectionId.'/rollover', $body);
    }

    /**
     * @param  array<string, mixed>  $enrollmentFields
     */
    private function createEnrollment(Student $student, Section $section, ?Term $term, array $enrollmentFields = []): Enrollment
    {
        return Enrollment::factory()->create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'term_id' => $term?->id,
            'enrolled_at' => self::ENROLLED_AT,
            ...$enrollmentFields,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createShsSection(string $name, array $sectionFields = []): Section
    {
        return Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name, ...$sectionFields]);
    }
}
