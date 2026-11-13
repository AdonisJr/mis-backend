<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Http\Requests\PreviewSectionRolloverRequest;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SectionRolloverPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ENROLLED_AT = '2030-08-01';

    private const ROW_KEYS = ['id', 'student_id', 'section_id', 'term_id', 'status', 'enrolled_at', 'ended_at', 'remarks', 'created_at', 'updated_at', 'student', 'section', 'term', 'is_enrolled_in_target', 'skip_reason'];

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2030-09-15');

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->firstTerm = Term::factory()->create([...self::FIRST_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->secondTerm = Term::factory()->create([...self::SECOND_TERM, 'school_year_id' => $this->schoolYear->id]);
        $this->andrea = Student::factory()->create(['lrn' => '136512340001', 'first_name' => 'Andrea', 'last_name' => 'Dela Cruz']);
        $this->miguel = Student::factory()->create(['lrn' => '136512340002', 'first_name' => 'Miguel', 'last_name' => 'Bautista']);
        $this->bea = Student::factory()->create(['lrn' => '136512340003', 'first_name' => 'Bea', 'last_name' => 'Reyes']);
        $this->carlo = Student::factory()->create(['lrn' => '136512340004', 'first_name' => 'Carlo', 'last_name' => 'Abad']);
        $this->adminToken = User::factory()->admin()->create()->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    public function test_preview_lists_enrolled_records_of_section_and_term_ordered_by_name(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->bea, $section, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->firstTerm, ['status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-08-20']);
        $this->createEnrollment($this->carlo, $otherSection, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->secondTerm);

        $previewResponse = $this->fetchPreview($section->id, $this->firstTerm->id)->assertOk()->assertJsonCount(3, 'enrollments');

        $this->assertSame(['Bautista', 'Dela Cruz', 'Reyes'], array_column(array_column($previewResponse->json('enrollments'), 'student'), 'last_name'));
        $this->assertEqualsCanonicalizing(self::ROW_KEYS, array_keys($previewResponse->json('enrollments.0')));
    }

    public function test_preview_flags_students_already_enrolled_in_target_term(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->andrea, $otherSection, $this->secondTerm);

        $previewResponse = $this->fetchPreview($section->id, $this->firstTerm->id)->assertOk();

        $miguelRow = $previewResponse->json('enrollments.0');
        $andreaRow = $previewResponse->json('enrollments.1');
        $this->assertSame([false, null], [$miguelRow['is_enrolled_in_target'], $miguelRow['skip_reason']]);
        $this->assertSame([true, EnrollmentController::ALREADY_ENROLLED_MESSAGE], [$andreaRow['is_enrolled_in_target'], $andreaRow['skip_reason']]);
    }

    public function test_preview_returns_next_term_and_seats_left(): void
    {
        $section = $this->createShsSection('STEM 11-A', ['capacity' => 5]);
        $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $this->createEnrollment($this->carlo, $section, $this->secondTerm);
        $this->createEnrollment($this->bea, $section, $this->secondTerm, ['status' => Enrollment::STATUS_COMPLETED, 'ended_at' => '2030-12-01']);

        $this->fetchPreview($section->id, $this->firstTerm->id)
            ->assertOk()
            ->assertJsonPath('target_term', ['id' => $this->secondTerm->id, 'term_number' => 2, 'label' => '2nd Semester'])
            ->assertJsonPath('seats_left', 4);
    }

    public function test_preview_of_last_term_returns_null_target(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $this->createEnrollment($this->andrea, $section, $this->secondTerm);

        $this->fetchPreview($section->id, $this->secondTerm->id)
            ->assertOk()
            ->assertJsonPath('target_term', null)
            ->assertJsonPath('seats_left', null)
            ->assertJsonCount(1, 'enrollments')
            ->assertJsonPath('enrollments.0.is_enrolled_in_target', false)
            ->assertJsonPath('enrollments.0.skip_reason', null);
    }

    public function test_preview_with_missing_next_term_returns_null_target(): void
    {
        $trimesterYear = SchoolYear::factory()->trimester()->create(self::SCHOOL_YEAR_2031);
        $trimesterFirstTerm = Term::factory()->create(['school_year_id' => $trimesterYear->id, 'term_number' => 1, 'start_date' => '2031-06-01', 'end_date' => '2031-09-30']);
        Term::factory()->create(['school_year_id' => $trimesterYear->id, 'term_number' => 3, 'start_date' => '2032-01-01', 'end_date' => '2032-03-31']);
        $section = $this->createShsSection('STEM 11-A', ['school_year_id' => $trimesterYear->id]);
        $this->createEnrollment($this->andrea, $section, $trimesterFirstTerm, ['enrolled_at' => '2031-06-10']);

        $this->fetchPreview($section->id, $trimesterFirstTerm->id)
            ->assertOk()
            ->assertJsonPath('target_term', null)
            ->assertJsonPath('seats_left', null)
            ->assertJsonCount(1, 'enrollments');
    }

    public function test_preview_rejects_jhs_section(): void
    {
        $jhsSection = Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'A']);

        $this->fetchPreview($jhsSection->id, $this->firstTerm->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => PreviewSectionRolloverRequest::JHS_ROLLOVER_MESSAGE]);
    }

    public function test_preview_rejects_term_of_other_school_year(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherYear = SchoolYear::factory()->create(['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31']);
        $otherTerm = Term::factory()->create(['school_year_id' => $otherYear->id, 'term_number' => 1, 'start_date' => '2032-06-01', 'end_date' => '2032-10-31']);

        $this->fetchPreview($section->id, $otherTerm->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_YEAR_MESSAGE]);
    }

    public function test_preview_rejects_invalid_term_id(): void
    {
        $section = $this->createShsSection('STEM 11-A');

        foreach ([null, self::MISSING_ID, '05', 'abc', [1]] as $invalidTermId) {
            $this->fetchPreview($section->id, $invalidTermId)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_id']);
        }
    }

    public function test_preview_of_missing_section_returns_404(): void
    {
        $this->fetchPreview(self::MISSING_ID, $this->firstTerm->id)->assertNotFound();
    }

    public function test_preview_query_count_is_independent_of_rows(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $this->createEnrollment($this->andrea, $section, $this->firstTerm);

        DB::enableQueryLog();
        $this->fetchPreview($section->id, $this->firstTerm->id)->assertOk()->assertJsonCount(1, 'enrollments');
        $singleRowCount = $this->countNonTokenQueries();

        $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $this->createEnrollment($this->bea, $section, $this->firstTerm);
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchPreview($section->id, $this->firstTerm->id)->assertOk()->assertJsonCount(3, 'enrollments');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }

    private function fetchPreview(int $sectionId, mixed $termId): TestResponse
    {
        $queryParams = $termId === null ? [] : ['term_id' => $termId];

        return $this->withToken($this->adminToken)->getJson(self::SECTIONS_URL.'/'.$sectionId.'/rollover-preview?'.http_build_query($queryParams));
    }

    /**
     * @param  array<string, mixed>  $enrollmentFields
     */
    private function createEnrollment(Student $student, Section $section, Term $term, array $enrollmentFields = []): Enrollment
    {
        return Enrollment::factory()->create([
            'student_id' => $student->id,
            'section_id' => $section->id,
            'term_id' => $term->id,
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
