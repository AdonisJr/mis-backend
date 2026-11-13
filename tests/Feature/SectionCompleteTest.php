<?php

namespace Tests\Feature;

use App\Http\Controllers\EnrollmentController;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SectionCompleteTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ENROLLED_AT = '2030-08-01';

    private const TODAY = '2030-09-15';

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

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

    public function test_complete_shs_section_closes_enrolled_records_of_term(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $otherSection = $this->createShsSection('STEM 11-B');
        $andreaRecord = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $miguelRecord = $this->createEnrollment($this->miguel, $section, $this->firstTerm);
        $otherTermRecord = $this->createEnrollment($this->bea, $section, $this->secondTerm);
        $closedRecord = $this->createEnrollment($this->carlo, $section, $this->firstTerm, ['status' => Enrollment::STATUS_DROPPED, 'ended_at' => '2030-08-20']);
        $otherSectionRecord = $this->createEnrollment($this->carlo, $otherSection, $this->firstTerm);

        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id])->assertOk()->assertExactJson(['completed_count' => 2]);

        foreach ([$andreaRecord, $miguelRecord] as $completedRecord) {
            $this->assertSame(Enrollment::STATUS_COMPLETED, $completedRecord->fresh()->status);
            $this->assertSame(self::TODAY, $completedRecord->fresh()->ended_at->toDateString());
        }
        $this->assertSame(Enrollment::STATUS_ENROLLED, $otherTermRecord->fresh()->status);
        $this->assertSame(Enrollment::STATUS_DROPPED, $closedRecord->fresh()->status);
        $this->assertSame('2030-08-20', $closedRecord->fresh()->ended_at->toDateString());
        $this->assertSame(Enrollment::STATUS_ENROLLED, $otherSectionRecord->fresh()->status);
    }

    public function test_complete_requires_term_for_shs_and_college(): void
    {
        $shsSection = $this->createShsSection('STEM 11-A');
        $collegeSection = Section::factory()->college()->create(['school_year_id' => $this->schoolYear->id, 'name' => 'BSIT 1-A']);
        $shsRecord = $this->createEnrollment($this->andrea, $shsSection, $this->firstTerm);

        foreach ([$shsSection, $collegeSection] as $termlessSection) {
            $this->putComplete($termlessSection->id, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_REQUIRED_MESSAGE]);
        }

        $this->assertSame(Enrollment::STATUS_ENROLLED, $shsRecord->fresh()->status);
    }

    public function test_complete_jhs_section_closes_whole_year_records(): void
    {
        $jhsSection = $this->createJhsSection('A');
        $otherJhsSection = $this->createJhsSection('B');
        $andreaRecord = $this->createEnrollment($this->andrea, $jhsSection, null);
        $miguelRecord = $this->createEnrollment($this->miguel, $jhsSection, null);
        $otherRecord = $this->createEnrollment($this->bea, $otherJhsSection, null);

        $this->putComplete($jhsSection->id, [])->assertOk()->assertExactJson(['completed_count' => 2]);

        $this->assertSame(Enrollment::STATUS_COMPLETED, $andreaRecord->fresh()->status);
        $this->assertSame(Enrollment::STATUS_COMPLETED, $miguelRecord->fresh()->status);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $otherRecord->fresh()->status);
    }

    public function test_complete_jhs_with_term_returns_422(): void
    {
        $jhsSection = $this->createJhsSection('A');
        $record = $this->createEnrollment($this->andrea, $jhsSection, null);

        $this->putComplete($jhsSection->id, ['term_id' => $this->firstTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_NOT_ALLOWED_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $record->fresh()->status);
    }

    public function test_complete_rejects_term_of_other_school_year(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $record = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $otherYear = SchoolYear::factory()->create(['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31']);
        $otherTerm = Term::factory()->create(['school_year_id' => $otherYear->id, 'term_number' => 1, 'start_date' => '2032-06-01', 'end_date' => '2032-10-31']);

        $this->putComplete($section->id, ['term_id' => $otherTerm->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_id' => StoreEnrollmentRequest::TERM_YEAR_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $record->fresh()->status);
    }

    public function test_complete_with_no_enrolled_records_returns_zero(): void
    {
        $section = $this->createShsSection('STEM 11-A');

        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id])->assertOk()->assertExactJson(['completed_count' => 0]);
    }

    public function test_complete_stores_given_ended_at_and_remarks(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $record = $this->createEnrollment($this->andrea, $section, $this->firstTerm, ['remarks' => 'Old note']);

        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id, 'ended_at' => '2030-10-31', 'remarks' => 'Term finished'])->assertOk();

        $this->assertSame('2030-10-31', $record->fresh()->ended_at->toDateString());
        $this->assertSame('Term finished', $record->fresh()->remarks);
    }

    public function test_complete_keeps_remarks_when_none_given(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $record = $this->createEnrollment($this->andrea, $section, $this->firstTerm, ['remarks' => 'Old note']);

        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id])->assertOk();

        $this->assertSame('Old note', $record->fresh()->remarks);
    }

    public function test_complete_ended_at_edges(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $earlyRecord = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $lateRecord = $this->createEnrollment($this->miguel, $section, $this->firstTerm, ['enrolled_at' => '2030-09-01']);

        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id, 'ended_at' => '2030-08-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at' => EnrollmentController::ENDED_BEFORE_ENROLLED_MESSAGE]);

        $this->assertSame(Enrollment::STATUS_ENROLLED, $earlyRecord->fresh()->status);
        $this->assertSame(Enrollment::STATUS_ENROLLED, $lateRecord->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->putComplete($section->id, ['term_id' => $this->firstTerm->id, 'ended_at' => '2030-09-01'])
            ->assertOk()
            ->assertJsonPath('completed_count', 2);
    }

    public function test_complete_rejects_invalid_body(): void
    {
        $section = $this->createShsSection('STEM 11-A');
        $record = $this->createEnrollment($this->andrea, $section, $this->firstTerm);
        $termId = $this->firstTerm->id;
        $invalidBodies = [
            'term_id (unknown)' => ['term_id' => self::MISSING_ID],
            'term_id (leading zero)' => ['term_id' => '05'],
            'ended_at' => ['term_id' => $termId, 'ended_at' => '15/09/2030'],
            'remarks' => ['term_id' => $termId, 'remarks' => str_repeat('a', 1001)],
        ];

        foreach ($invalidBodies as $rejectedField => $invalidBody) {
            $this->putComplete($section->id, $invalidBody)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([explode(' ', $rejectedField)[0]]);
        }

        $this->assertSame(Enrollment::STATUS_ENROLLED, $record->fresh()->status);
    }

    public function test_complete_of_missing_section_returns_404(): void
    {
        $this->putComplete(self::MISSING_ID, ['term_id' => $this->firstTerm->id])->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function putComplete(int $sectionId, array $body): TestResponse
    {
        return $this->withToken($this->adminToken)->putJson(self::SECTIONS_URL.'/'.$sectionId.'/complete', $body);
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

    private function createShsSection(string $name): Section
    {
        return Section::factory()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name]);
    }

    private function createJhsSection(string $name): Section
    {
        return Section::factory()->jhs()->create(['school_year_id' => $this->schoolYear->id, 'name' => $name]);
    }
}
