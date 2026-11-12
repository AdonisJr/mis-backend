<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SectionListTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const PAGE_SIZE = 15;

    private const DEFAULT_CAPACITY = 40;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const SCHOOL_YEAR_2032 = ['name' => '2032-2033', 'start_date' => '2032-06-01', 'end_date' => '2033-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const ABM_PROGRAM = ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    private const GRADE_7 = 'Grade 7';

    private const GRADE_11 = 'Grade 11';

    private const GRADE_12 = 'Grade 12';

    private const FIRST_YEAR_COLLEGE = '1st Year College';

    private const SECTION_KEYS = ['id', 'school_year_id', 'grade_level_id', 'program_id', 'name', 'capacity', 'room', 'created_at', 'updated_at', 'grade_level', 'program'];

    private SchoolYear $schoolYear;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->adminToken = $this->createAdminToken();
    }

    public function test_list_returns_first_page_with_meta(): void
    {
        Section::factory()->count(16)->create(['school_year_id' => $this->schoolYear->id]);

        $this->fetchSectionList([])
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'sections')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('links');
    }

    public function test_list_returns_second_page(): void
    {
        Section::factory()->count(16)->create(['school_year_id' => $this->schoolYear->id]);

        $this->fetchSectionList(['page' => 2])
            ->assertOk()
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('meta.current_page', 2);
        $this->fetchSectionList(['page' => 3])
            ->assertOk()
            ->assertJsonPath('sections', [])
            ->assertJsonPath('meta.total', 16);
    }

    public function test_list_ignores_per_page_param(): void
    {
        Section::factory()->count(16)->create(['school_year_id' => $this->schoolYear->id]);

        $this->fetchSectionList(['per_page' => 5])
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'sections')
            ->assertJsonPath('meta.per_page', self::PAGE_SIZE);
    }

    public function test_list_returns_exact_section_shape(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $stemSection = $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-A', 'room' => 'Rm 101'])->fresh();
        $jhsSection = $this->createSection(self::GRADE_7, ['name' => 'A'])->fresh();

        $listResponse = $this->fetchSectionList([])
            ->assertOk()
            ->assertExactJson([
                'sections' => [
                    [
                        ...$this->buildSectionColumns($jhsSection),
                        'grade_level' => ['id' => $jhsSection->grade_level_id, 'name' => self::GRADE_7, 'stage' => GradeLevel::STAGE_JHS],
                        'program' => null,
                    ],
                    [
                        ...$this->buildSectionColumns($stemSection),
                        'grade_level' => ['id' => $stemSection->grade_level_id, 'name' => self::GRADE_11, 'stage' => GradeLevel::STAGE_SHS],
                        'program' => ['id' => $stemProgram->id, 'code' => 'STEM', 'name' => self::STEM_PROGRAM['name']],
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 2],
            ]);

        foreach ($listResponse->json('sections') as $listedSection) {
            $this->assertSame(self::SECTION_KEYS, array_keys($listedSection));
        }
    }

    public function test_list_orders_by_grade_level_program_code_and_name(): void
    {
        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2032);
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $this->createSection(self::GRADE_12, ['program_id' => $stemProgram->id, 'name' => 'B']);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'B']);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'A']);
        $this->createSection(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'C']);
        $this->createSection(self::GRADE_7, ['name' => 'A']);

        $listedSections = $this->fetchSectionList([])->assertOk()->json('sections');

        $this->assertSame([
            [self::GRADE_7, null, 'A'],
            [self::GRADE_11, 'ABM', 'C'],
            [self::GRADE_11, 'STEM', 'A'],
            [self::GRADE_11, 'STEM', 'B'],
            [self::GRADE_12, 'STEM', 'B'],
        ], array_map(fn (array $listedSection) => [$listedSection['grade_level']['name'], $listedSection['program']['code'] ?? null, $listedSection['name']], $listedSections));
    }

    public function test_list_requires_school_year(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id=')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_returns_only_sections_of_school_year(): void
    {
        $this->createSection(self::GRADE_7, ['name' => 'A']);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2032);
        Section::factory()->create([...$this->buildSectionPayload(self::GRADE_7, ['name' => 'B']), 'school_year_id' => $otherYear->id]);

        $listResponse = $this->fetchSectionList([])->assertOk();

        $this->assertSame(['A'], $this->extractSectionNames($listResponse));
        $this->assertSame([$this->schoolYear->id], array_column($listResponse->json('sections'), 'school_year_id'));
    }

    public function test_list_returns_empty_sections_for_school_year_without_sections(): void
    {
        $emptyYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2032);

        $this->fetchSectionList(['school_year_id' => $emptyYear->id])
            ->assertOk()
            ->assertJsonPath('sections', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        foreach ([self::MISSING_ID, 'abc', ['1']] as $invalidSchoolYearId) {
            $this->assertRejectedFilter(['school_year_id' => $invalidSchoolYearId], 'school_year_id');
        }
        foreach ([self::MISSING_ID, 'abc'] as $invalidGradeLevelId) {
            $this->assertRejectedFilter(['grade_level_id' => $invalidGradeLevelId], 'grade_level_id');
        }
        foreach ([self::MISSING_ID, '1.5'] as $invalidProgramId) {
            $this->assertRejectedFilter(['program_id' => $invalidProgramId], 'program_id');
        }
        foreach ([['x'], str_repeat('s', 256)] as $invalidSearch) {
            $this->assertRejectedFilter(['search' => $invalidSearch], 'search');
        }
    }

    public function test_filter_by_grade_level(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $this->createSection(self::GRADE_7, ['name' => 'A']);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-A']);

        $this->assertSame(['STEM 11-A'], $this->extractSectionNames($this->fetchSectionList(['grade_level_id' => $this->findGradeLevelId(self::GRADE_11)])));
    }

    public function test_filter_by_program(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-A']);
        $this->createSection(self::GRADE_11, ['program_id' => $abmProgram->id, 'name' => 'C']);

        $this->assertSame(['STEM 11-A'], $this->extractSectionNames($this->fetchSectionList(['program_id' => $stemProgram->id])));
    }

    public function test_search_by_name_is_case_insensitive_and_partial(): void
    {
        $this->createSearchFixtures();

        $this->assertSame(['STEM 11-A'], $this->extractSectionNames($this->fetchSectionList(['search' => '11-a'])));
        $this->assertSame(['Rizal'], $this->extractSectionNames($this->fetchSectionList(['search' => 'rizal'])));
    }

    public function test_search_words_must_all_match(): void
    {
        $this->createSearchFixtures();

        $this->assertSame(['STEM 11-B'], $this->extractSectionNames($this->fetchSectionList(['search' => 'stem 11-b'])));
        $this->assertSame(['STEM 11-A', 'STEM 11-B'], $this->extractSectionNames($this->fetchSectionList(['search' => 'stem'])));
    }

    public function test_filters_and_search_combine(): void
    {
        $this->createSearchFixtures();
        $this->createSection(self::GRADE_7, ['name' => 'A']);

        $this->assertSame(
            ['STEM 11-A', 'STEM 11-B'],
            $this->extractSectionNames($this->fetchSectionList(['grade_level_id' => $this->findGradeLevelId(self::GRADE_11), 'search' => 'stem'])),
        );
        $this->assertSame([], $this->extractSectionNames($this->fetchSectionList(['grade_level_id' => $this->findGradeLevelId(self::GRADE_7), 'search' => 'stem'])));
    }

    public function test_search_with_sql_metacharacters_is_safe(): void
    {
        $this->createSearchFixtures();

        $this->fetchSectionList(['search' => "' OR 1=1 --"])
            ->assertOk()
            ->assertJsonPath('sections', []);
    }

    public function test_list_query_count_does_not_grow_with_rows(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $bsitProgram = Program::factory()->create(self::BSIT_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'A']);

        DB::enableQueryLog();
        $this->fetchSectionList([])->assertOk()->assertJsonCount(1, 'sections');
        $singleRowCount = $this->countNonTokenQueries();

        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'B']);
        $this->createSection(self::GRADE_7, ['name' => 'A']);
        $this->createSection(self::FIRST_YEAR_COLLEGE, ['program_id' => $bsitProgram->id, 'name' => 'C']);
        $this->createSection(self::GRADE_12, ['program_id' => $stemProgram->id, 'name' => 'D']);
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchSectionList([])->assertOk()->assertJsonCount(5, 'sections');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }

    private function createSearchFixtures(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-A']);
        $this->createSection(self::GRADE_11, ['program_id' => $stemProgram->id, 'name' => 'STEM 11-B']);
        $this->createSection(self::GRADE_12, ['program_id' => $stemProgram->id, 'name' => 'Rizal']);
    }

    /**
     * @param  array<string, mixed>  $filterParams
     */
    private function assertRejectedFilter(array $filterParams, string $rejectedField): void
    {
        $this->fetchSectionList($filterParams)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$rejectedField]);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchSectionList(array $queryParams): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, ...$queryParams]));
    }

    /**
     * @return list<string>
     */
    private function extractSectionNames(TestResponse $listResponse): array
    {
        return array_column($listResponse->assertOk()->json('sections'), 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSectionColumns(Section $section): array
    {
        return [
            'id' => $section->id,
            'school_year_id' => $section->school_year_id,
            'grade_level_id' => $section->grade_level_id,
            'program_id' => $section->program_id,
            'name' => $section->name,
            'capacity' => $section->capacity,
            'room' => $section->room,
            'created_at' => $section->created_at->toJSON(),
            'updated_at' => $section->updated_at->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     * @return array<string, mixed>
     */
    private function buildSectionPayload(string $gradeLevelName, array $sectionFields): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => $this->findGradeLevelId($gradeLevelName),
            'program_id' => null,
            'capacity' => self::DEFAULT_CAPACITY,
            'room' => null,
            ...$sectionFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createSection(string $gradeLevelName, array $sectionFields): Section
    {
        return Section::factory()->create($this->buildSectionPayload($gradeLevelName, $sectionFields));
    }

    private function findGradeLevelId(string $gradeLevelName): int
    {
        return GradeLevel::where('name', $gradeLevelName)->value('id');
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor(User::factory()->admin()->create(['email' => 'acting@admin.test']));
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
