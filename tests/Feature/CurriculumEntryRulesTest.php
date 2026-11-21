<?php

namespace Tests\Feature;

use App\Http\Requests\StoreCurriculumEntryRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Models\CurriculumEntry;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class CurriculumEntryRulesTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private Program $stemProgram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideMismatchedTypes(): array
    {
        return [
            'jhs subject in shs grade' => [self::GRADE_11, Subject::TYPE_JHS_LEARNING_AREA],
            'shs core in jhs grade' => [self::GRADE_7, Subject::TYPE_SHS_CORE],
            'shs specialized in jhs grade' => [self::GRADE_7, Subject::TYPE_SHS_SPECIALIZED],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideShsTypes(): array
    {
        return [
            'core' => [Subject::TYPE_SHS_CORE],
            'applied' => [Subject::TYPE_SHS_APPLIED],
            'specialized' => [Subject::TYPE_SHS_SPECIALIZED],
        ];
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideOutOfRangeTerms(): array
    {
        return [
            'semester 3' => [SchoolYear::TERM_TYPE_SEMESTER, 3],
            'semester 0' => [SchoolYear::TERM_TYPE_SEMESTER, 0],
            'trimester 4' => [SchoolYear::TERM_TYPE_TRIMESTER, 4],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFields(): array
    {
        return [
            'school year missing' => [['school_year_id' => null], 'school_year_id'],
            'grade level missing' => [['grade_level_id' => null], 'grade_level_id'],
            'subject missing' => [['subject_id' => null], 'subject_id'],
            'subject unknown' => [['subject_id' => self::MISSING_ID], 'subject_id'],
            'program malformed' => [['program_id' => 'abc'], 'program_id'],
            'sort order zero' => [['sort_order' => 0], 'sort_order'],
            'sort order too large' => [['sort_order' => 10000], 'sort_order'],
            'sort order fractional' => [['sort_order' => 1.5], 'sort_order'],
        ];
    }

    public function test_adds_junior_high_entry(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $addResponse = $this->postEntry($this->buildJhsEntryPayload($subject))->assertCreated();

        $addResponse->assertJsonPath('curriculum_entry.program_id', null)
            ->assertJsonPath('curriculum_entry.term_number', null)
            ->assertJsonPath('curriculum_entry.sort_order', null)
            ->assertJsonPath('curriculum_entry.subject.code', self::MATH_7)
            ->assertJsonPath('curriculum_entry.grade_level.stage', GradeLevel::STAGE_JHS)
            ->assertJsonPath('curriculum_entry.program', null);
        $this->assertEqualsCanonicalizing(self::ENTRY_KEYS, array_keys($addResponse->json('curriculum_entry')));
        $this->assertDatabaseCount('curriculum_entries', 1);
    }

    public function test_adds_senior_high_entry(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);

        $this->postEntry($this->buildShsEntryPayload($subject, $this->stemProgram))
            ->assertCreated()
            ->assertJsonPath('curriculum_entry.program_id', $this->stemProgram->id)
            ->assertJsonPath('curriculum_entry.term_number', 1)
            ->assertJsonPath('curriculum_entry.program.code', 'STEM')
            ->assertJsonPath('curriculum_entry.grade_level.stage', GradeLevel::STAGE_SHS);
    }

    public function test_adds_entry_with_sort_order(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->postEntry($this->buildJhsEntryPayload($subject, ['sort_order' => 3]))
            ->assertCreated()
            ->assertJsonPath('curriculum_entry.sort_order', 3);
    }

    public function test_rejects_college_grade_level(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $payload = $this->buildJhsEntryPayload($subject, ['grade_level_id' => $this->findGradeLevelId(self::FIRST_YEAR_COLLEGE)]);

        $this->postEntry($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id' => StoreCurriculumEntryRequest::GRADE_LEVEL_NOT_CURRICULAR_MESSAGE]);

        $this->assertDatabaseCount('curriculum_entries', 0);
    }

    public function test_rejects_inactive_subject(): void
    {
        $subject = Subject::factory()->inactive()->create(['code' => self::MATH_7]);

        $this->postEntry($this->buildJhsEntryPayload($subject))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id' => StoreCurriculumEntryRequest::SUBJECT_INACTIVE_MESSAGE]);
    }

    public function test_senior_high_entry_needs_program(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $payload = $this->buildShsEntryPayload($subject, $this->stemProgram);
        unset($payload['program_id']);

        $this->postEntry($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_REQUIRED_MESSAGE]);
    }

    public function test_senior_high_entry_needs_term(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $payload = $this->buildShsEntryPayload($subject, $this->stemProgram);
        unset($payload['term_number']);

        $this->postEntry($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => StoreCurriculumEntryRequest::TERM_REQUIRED_MESSAGE]);
    }

    public function test_junior_high_entry_rejects_program(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->postEntry($this->buildJhsEntryPayload($subject, ['program_id' => $this->stemProgram->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreCurriculumEntryRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);
    }

    public function test_junior_high_entry_rejects_term(): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->postEntry($this->buildJhsEntryPayload($subject, ['term_number' => 1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => StoreCurriculumEntryRequest::TERM_NOT_ALLOWED_MESSAGE]);
    }

    public function test_senior_high_entry_rejects_college_program(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $collegeProgram = $this->createProgram(self::BSIT_PROGRAM);

        $this->postEntry($this->buildShsEntryPayload($subject, $collegeProgram))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_STAGE_MESSAGE]);
    }

    public function test_senior_high_entry_rejects_inactive_program(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $inactiveProgram = Program::factory()->inactive()->create(self::ABM_PROGRAM);

        $this->postEntry($this->buildShsEntryPayload($subject, $inactiveProgram))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE]);
    }

    public function test_accepts_term_three_in_trimester_year(): void
    {
        $trimesterYear = SchoolYear::factory()->create([...self::SCHOOL_YEAR_2031, 'term_type' => SchoolYear::TERM_TYPE_TRIMESTER]);
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $payload = [...$this->buildShsEntryPayload($subject, $this->stemProgram), 'school_year_id' => $trimesterYear->id, 'term_number' => 3];

        $this->postEntry($payload)->assertCreated()->assertJsonPath('curriculum_entry.term_number', 3);
    }

    public function test_rejects_duplicate_jhs_entry(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $this->createEntry($this->buildJhsEntryPayload($subject));

        $this->postEntry($this->buildJhsEntryPayload($subject))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id' => StoreCurriculumEntryRequest::KEY_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('curriculum_entries', 1);
    }

    public function test_rejects_duplicate_shs_entry(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry($this->buildShsEntryPayload($subject, $this->stemProgram));

        $this->postEntry($this->buildShsEntryPayload($subject, $this->stemProgram))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id' => StoreCurriculumEntryRequest::KEY_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('curriculum_entries', 1);
    }

    public function test_allows_same_subject_in_other_term_and_program(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $abmProgram = $this->createProgram(self::ABM_PROGRAM);
        $this->createEntry($this->buildShsEntryPayload($subject, $this->stemProgram));

        $this->postEntry([...$this->buildShsEntryPayload($subject, $this->stemProgram), 'term_number' => 2])->assertCreated();
        $this->postEntry($this->buildShsEntryPayload($subject, $abmProgram))->assertCreated();

        $this->assertDatabaseCount('curriculum_entries', 3);
    }

    #[DataProvider('provideMismatchedTypes')]
    public function test_rejects_subject_type_not_matching_grade_level_stage(string $gradeLevelName, string $subjectType): void
    {
        $subject = $this->createSubject(self::MATH_7, $subjectType);
        $payload = $gradeLevelName === self::GRADE_7
            ? $this->buildJhsEntryPayload($subject)
            : $this->buildShsEntryPayload($subject, $this->stemProgram);

        $this->postEntry($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id' => StoreCurriculumEntryRequest::SUBJECT_TYPE_MESSAGE]);

        $this->assertDatabaseCount('curriculum_entries', 0);
    }

    #[DataProvider('provideShsTypes')]
    public function test_accepts_every_senior_high_subject_type(string $subjectType): void
    {
        $subject = $this->createSubject(self::GEN_MATH, $subjectType);

        $this->postEntry($this->buildShsEntryPayload($subject, $this->stemProgram))
            ->assertCreated()
            ->assertJsonPath('curriculum_entry.subject.type', $subjectType);
    }

    #[DataProvider('provideOutOfRangeTerms')]
    public function test_rejects_term_out_of_range(string $termType, int $termNumber): void
    {
        $schoolYear = SchoolYear::factory()->create([...self::SCHOOL_YEAR_2031, 'term_type' => $termType]);
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $payload = [...$this->buildShsEntryPayload($subject, $this->stemProgram), 'school_year_id' => $schoolYear->id, 'term_number' => $termNumber];

        $this->postEntry($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number' => sprintf(StoreCurriculumEntryRequest::TERM_RANGE_MESSAGE, SchoolYear::TERM_COUNTS[$termType])]);

        $this->assertDatabaseCount('curriculum_entries', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('provideInvalidFields')]
    public function test_rejects_invalid_ids_and_sort_order(array $overrides, string $errorField): void
    {
        $subject = $this->createSubject(self::MATH_7);

        $this->postEntry($this->buildJhsEntryPayload($subject, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertSame(0, CurriculumEntry::count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postEntry(array $payload): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::CURRICULUM_URL, $payload);
    }
}
