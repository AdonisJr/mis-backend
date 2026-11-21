<?php

namespace Tests\Feature;

use App\Http\Controllers\CurriculumCopyController;
use App\Http\Requests\CopyCurriculumRequest;
use App\Http\Requests\CopyFeeSchedulesRequest;
use App\Models\CurriculumEntry;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class CurriculumCopyTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private SchoolYear $targetYear;

    private Program $stemProgram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
    }

    public function test_copy_copies_every_entry_with_its_sort_order(): void
    {
        $jhsSubject = $this->createSubject(self::MATH_7);
        $shsSubject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry([...$this->buildJhsEntryPayload($jhsSubject), 'sort_order' => 2]);
        $this->createEntry([...$this->buildShsEntryPayload($shsSubject, $this->stemProgram), 'sort_order' => 7]);

        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 2, 'skipped_count' => 0, 'skipped' => []]);

        $copiedEntries = CurriculumEntry::where('school_year_id', $this->targetYear->id)->orderBy('id')->get();
        $this->assertCount(2, $copiedEntries);
        $this->assertSame([$jhsSubject->id, $shsSubject->id], $copiedEntries->pluck('subject_id')->all());
        $this->assertSame([2, 7], $copiedEntries->pluck('sort_order')->all());
        $this->assertSame([null, $this->stemProgram->id], $copiedEntries->pluck('program_id')->all());
        $this->assertSame([null, 1], $copiedEntries->pluck('term_number')->all());
    }

    public function test_copy_skips_inactive_subject(): void
    {
        $subject = Subject::factory()->inactive()->create(['code' => self::MATH_7]);
        $this->createEntry($this->buildJhsEntryPayload($subject));

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('copied_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped.0.reason', CurriculumCopyController::SKIP_SUBJECT_INACTIVE_REASON)
            ->assertJsonPath('skipped.0.subject.code', self::MATH_7)
            ->assertJsonPath('skipped.0.grade_level.name', self::GRADE_7);

        $this->assertSame(0, CurriculumEntry::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_copy_skips_inactive_program(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry($this->buildShsEntryPayload($subject, $this->stemProgram));
        $this->stemProgram->forceFill(['is_active' => false])->save();

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('copied_count', 0)
            ->assertJsonPath('skipped.0.reason', CurriculumCopyController::SKIP_PROGRAM_INACTIVE_REASON)
            ->assertJsonPath('skipped.0.program.code', 'STEM')
            ->assertJsonPath('skipped.0.term_number', 1);
    }

    public function test_skips_term_numbers_missing_in_target_term_type(): void
    {
        $trimesterYear = SchoolYear::factory()->create([...self::SCHOOL_YEAR_2030, 'name' => '2029-2030', 'start_date' => '2029-06-01', 'end_date' => '2030-03-31', 'term_type' => SchoolYear::TERM_TYPE_TRIMESTER]);
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry([...$this->buildShsEntryPayload($subject, $this->stemProgram), 'school_year_id' => $trimesterYear->id, 'term_number' => 3]);
        $this->createEntry([...$this->buildShsEntryPayload($subject, $this->stemProgram), 'school_year_id' => $trimesterYear->id, 'term_number' => 2]);

        $this->postCopy($trimesterYear)
            ->assertOk()
            ->assertJsonPath('copied_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped.0.reason', CurriculumCopyController::SKIP_TERM_MISSING_REASON)
            ->assertJsonPath('skipped.0.term_number', 3);

        $this->assertSame([2], CurriculumEntry::where('school_year_id', $this->targetYear->id)->pluck('term_number')->all());
    }

    public function test_copy_reports_counts_and_reasons_for_a_mixed_set(): void
    {
        $activeSubject = $this->createSubject(self::MATH_7);
        $inactiveSubject = Subject::factory()->inactive()->create(['code' => self::ENGLISH_7]);
        $inactiveProgram = Program::factory()->inactive()->create(self::ABM_PROGRAM);
        $shsSubject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry($this->buildJhsEntryPayload($activeSubject));
        $this->createEntry($this->buildJhsEntryPayload($inactiveSubject));
        $this->createEntry($this->buildShsEntryPayload($shsSubject, $inactiveProgram));
        $this->createEntry($this->buildShsEntryPayload($shsSubject, $this->stemProgram));

        $copyResponse = $this->postCopy()->assertOk();

        $copyResponse->assertJsonPath('copied_count', 2)->assertJsonPath('skipped_count', 2);
        $this->assertEqualsCanonicalizing(
            [CurriculumCopyController::SKIP_SUBJECT_INACTIVE_REASON, CurriculumCopyController::SKIP_PROGRAM_INACTIVE_REASON],
            $copyResponse->json('skipped.*.reason'),
        );
        $this->assertSame(2, CurriculumEntry::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_refuses_non_empty_target(): void
    {
        $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));
        $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7)), 'school_year_id' => $this->targetYear->id]);

        $this->postCopy()
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target_school_year_id' => CopyCurriculumRequest::TARGET_NOT_EMPTY_MESSAGE]);

        $this->assertSame(1, CurriculumEntry::where('school_year_id', $this->targetYear->id)->count());
        $this->assertDatabaseCount('curriculum_entries', 2);
    }

    public function test_refuses_same_year(): void
    {
        $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));

        $this->postJsonCopy($this->schoolYear->id, $this->schoolYear->id)
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => CopyFeeSchedulesRequest::SAME_YEAR_MESSAGE,
                'errors' => ['target_school_year_id' => [CopyFeeSchedulesRequest::SAME_YEAR_MESSAGE]],
            ]);

        $this->assertDatabaseCount('curriculum_entries', 1);
    }

    public function test_copy_of_empty_source_copies_nothing(): void
    {
        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 0, 'skipped_count' => 0, 'skipped' => []]);

        $this->assertDatabaseCount('curriculum_entries', 0);
    }

    public function test_copy_leaves_the_source_unchanged(): void
    {
        $sourceEntry = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::MATH_7)), 'sort_order' => 4]);

        $this->postCopy()->assertOk();

        $reloadedSource = $sourceEntry->fresh();
        $this->assertSame($this->schoolYear->id, $reloadedSource->school_year_id);
        $this->assertSame(4, $reloadedSource->sort_order);
        $this->assertSame(1, CurriculumEntry::where('school_year_id', $this->schoolYear->id)->count());
    }

    public function test_copy_rejects_missing_source_year(): void
    {
        $this->postJsonCopy(null, $this->targetYear->id)->assertUnprocessable()->assertJsonValidationErrors(['source_school_year_id']);
    }

    public function test_copy_rejects_missing_target_year(): void
    {
        $this->postJsonCopy($this->schoolYear->id, null)->assertUnprocessable()->assertJsonValidationErrors(['target_school_year_id']);
    }

    public function test_copy_rejects_unknown_target_year(): void
    {
        $this->postJsonCopy($this->schoolYear->id, self::MISSING_ID)->assertUnprocessable()->assertJsonValidationErrors(['target_school_year_id']);
    }

    private function postCopy(?SchoolYear $sourceYear = null): TestResponse
    {
        return $this->postJsonCopy(($sourceYear ?? $this->schoolYear)->id, $this->targetYear->id);
    }

    private function postJsonCopy(?int $sourceYearId, ?int $targetYearId): TestResponse
    {
        return $this->withToken($this->adminToken)->postJson(self::CURRICULUM_COPY_URL, [
            'source_school_year_id' => $sourceYearId,
            'target_school_year_id' => $targetYearId,
        ]);
    }
}
