<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithCurriculum;
use Tests\TestCase;

class CurriculumListTest extends TestCase
{
    use InteractsWithCurriculum;
    use RefreshDatabase;

    private const UNPAGINATED_ROW_COUNT = 20;

    private Program $stemProgram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->stemProgram = $this->createProgram(self::STEM_PROGRAM);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'grade level malformed' => ['grade_level_id=abc'],
            'program unknown' => ['program_id=999'],
            'term zero' => ['term_number=0'],
        ];
    }

    public function test_list_requires_school_year_id(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::CURRICULUM_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_returns_entry_and_subject_fields(): void
    {
        $subject = $this->createSubject(self::MATH_7);
        $entry = $this->createEntry([...$this->buildJhsEntryPayload($subject), 'sort_order' => 2]);

        $listResponse = $this->getList()->assertOk();

        $this->assertSame(['curriculum_entries', 'group_totals'], array_keys($listResponse->json()));
        $this->assertEqualsCanonicalizing(self::ENTRY_KEYS, array_keys($listResponse->json('curriculum_entries.0')));
        $this->assertEqualsCanonicalizing(self::ENTRY_SUBJECT_KEYS, array_keys($listResponse->json('curriculum_entries.0.subject')));
        $listResponse->assertJsonPath('curriculum_entries.0.id', $entry->id)
            ->assertJsonPath('curriculum_entries.0.sort_order', 2)
            ->assertJsonPath('curriculum_entries.0.subject.weekly_hours', '4.0')
            ->assertJsonPath('curriculum_entries.0.grade_level.name', self::GRADE_7)
            ->assertJsonPath('curriculum_entries.0.program', null);
    }

    public function test_list_is_scoped_to_the_school_year(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $thisYearEntry = $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));
        $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7)), 'school_year_id' => $otherYear->id]);

        $listResponse = $this->getList()->assertOk();

        $this->assertSame([$thisYearEntry->id], $listResponse->json('curriculum_entries.*.id'));
    }

    public function test_list_filters_by_grade_level(): void
    {
        $grade7Entry = $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));
        $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7), ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]));

        $listResponse = $this->getList('&grade_level_id='.$this->findGradeLevelId(self::GRADE_7))->assertOk();

        $this->assertSame([$grade7Entry->id], $listResponse->json('curriculum_entries.*.id'));
    }

    public function test_list_filters_by_program(): void
    {
        $abmProgram = $this->createProgram(self::ABM_PROGRAM);
        $stemEntry = $this->createEntry($this->buildShsEntryPayload($this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE), $this->stemProgram));
        $this->createEntry($this->buildShsEntryPayload($this->createSubject(self::ORAL_COM, Subject::TYPE_SHS_CORE), $abmProgram));

        $listResponse = $this->getList('&program_id='.$this->stemProgram->id)->assertOk();

        $this->assertSame([$stemEntry->id], $listResponse->json('curriculum_entries.*.id'));
    }

    public function test_list_filters_by_term(): void
    {
        $subject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry($this->buildShsEntryPayload($subject, $this->stemProgram));
        $secondTermEntry = $this->createEntry([...$this->buildShsEntryPayload($subject, $this->stemProgram), 'term_number' => 2]);

        $listResponse = $this->getList('&term_number=2')->assertOk();

        $this->assertSame([$secondTermEntry->id], $listResponse->json('curriculum_entries.*.id'));
    }

    public function test_list_orders_by_group_then_sort_order_with_nulls_last_then_id(): void
    {
        $grade8Entry = $this->createEntry($this->buildJhsEntryPayload($this->createSubject('G8SUBJ'), ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8), 'sort_order' => 1]));
        $unsortedFirst = $this->createEntry($this->buildJhsEntryPayload($this->createSubject('NOSORT1')));
        $sortedSecond = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject('SORT2')), 'sort_order' => 2]);
        $unsortedSecond = $this->createEntry($this->buildJhsEntryPayload($this->createSubject('NOSORT2')));
        $sortedFirst = $this->createEntry([...$this->buildJhsEntryPayload($this->createSubject('SORT1')), 'sort_order' => 1]);
        $programEntry = $this->createEntry($this->buildShsEntryPayload($this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE), $this->stemProgram));

        $listedIds = $this->getList()->assertOk()->json('curriculum_entries.*.id');

        $this->assertSame([
            $sortedFirst->id,
            $sortedSecond->id,
            $unsortedFirst->id,
            $unsortedSecond->id,
            $grade8Entry->id,
            $programEntry->id,
        ], $listedIds);
    }

    public function test_list_returns_group_totals_with_exact_decimals(): void
    {
        foreach ([self::MATH_7 => '1.5', self::ENGLISH_7 => '1.5', 'SCI7' => '1.5'] as $code => $weeklyHours) {
            $subject = Subject::factory()->create(['code' => $code, 'weekly_hours' => $weeklyHours]);
            $this->createEntry($this->buildJhsEntryPayload($subject));
        }

        foreach (['A8' => '0.5', 'B8' => '0.5'] as $code => $weeklyHours) {
            $subject = Subject::factory()->create(['code' => $code, 'weekly_hours' => $weeklyHours]);
            $this->createEntry($this->buildJhsEntryPayload($subject, ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]));
        }

        $totalsResponse = $this->getList()->assertOk();

        $this->assertCount(2, $totalsResponse->json('group_totals'));
        $this->assertEqualsCanonicalizing(self::GROUP_TOTAL_KEYS, array_keys($totalsResponse->json('group_totals.0')));
        $totalsResponse->assertJsonPath('group_totals.0', [
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_7),
            'program_id' => null,
            'term_number' => null,
            'entries_count' => 3,
            'weekly_hours_total' => '4.5',
        ]);
        $totalsResponse->assertJsonPath('group_totals.1.weekly_hours_total', '1.0')
            ->assertJsonPath('group_totals.1.entries_count', 2);
    }

    public function test_group_totals_follow_the_filters(): void
    {
        $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::MATH_7)));
        $this->createEntry($this->buildJhsEntryPayload($this->createSubject(self::ENGLISH_7), ['grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]));
        $shsSubject = $this->createSubject(self::GEN_MATH, Subject::TYPE_SHS_CORE);
        $this->createEntry($this->buildShsEntryPayload($shsSubject, $this->stemProgram));
        $this->createEntry([...$this->buildShsEntryPayload($shsSubject, $this->stemProgram), 'term_number' => 2]);

        $allFilters = '&grade_level_id='.$this->findGradeLevelId(self::GRADE_11).'&program_id='.$this->stemProgram->id.'&term_number=2';
        $filteredResponse = $this->getList($allFilters)->assertOk();
        $gradeOnlyResponse = $this->getList('&grade_level_id='.$this->findGradeLevelId(self::GRADE_11))->assertOk();

        $this->assertCount(1, $filteredResponse->json('group_totals'));
        $filteredResponse->assertJsonPath('group_totals.0.term_number', 2)->assertJsonPath('group_totals.0.entries_count', 1);
        $this->assertCount(2, $gradeOnlyResponse->json('group_totals'));
    }

    public function test_list_is_unpaginated(): void
    {
        foreach (Subject::factory()->count(self::UNPAGINATED_ROW_COUNT)->create() as $subject) {
            $this->createEntry($this->buildJhsEntryPayload($subject));
        }

        $listResponse = $this->getList()->assertOk();

        $this->assertCount(self::UNPAGINATED_ROW_COUNT, $listResponse->json('curriculum_entries'));
        $this->assertArrayNotHasKey('meta', $listResponse->json());
        $listResponse->assertJsonPath('group_totals.0.entries_count', self::UNPAGINATED_ROW_COUNT);
    }

    public function test_list_of_empty_year_returns_empty_lists(): void
    {
        $this->getList()
            ->assertOk()
            ->assertExactJson(['curriculum_entries' => [], 'group_totals' => []]);
    }

    #[DataProvider('provideInvalidFilters')]
    public function test_list_rejects_invalid_filters(string $queryString): void
    {
        $this->getList('&'.$queryString)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([strtok($queryString, '=')]);
    }

    private function getList(string $extraQuery = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::CURRICULUM_URL.'?school_year_id='.$this->schoolYear->id.$extraQuery);
    }
}
