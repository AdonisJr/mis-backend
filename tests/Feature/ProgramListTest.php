<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramListTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMS_URL = '/api/mis/programs';

    private const TOKEN_NAME = 'test';

    private const PAGE_SIZE = 15;

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const PROGRAM_KEYS = ['id', 'code', 'name', 'stage', 'description', 'is_active', 'created_at', 'updated_at'];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const ABM_PROGRAM = ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    private const BSBA_PROGRAM = ['code' => 'BSBA', 'name' => 'Bachelor of Science in Business Administration', 'stage' => GradeLevel::STAGE_COLLEGE];

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->createTokenFor($this->createActingAdmin());
    }

    public function test_list_returns_first_page_with_meta(): void
    {
        Program::factory()->count(16)->create();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'programs')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('links');
    }

    public function test_list_returns_second_page(): void
    {
        Program::factory()->count(16)->create();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL.'?'.http_build_query(['page' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_ignores_per_page_param(): void
    {
        Program::factory()->count(16)->create();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL.'?'.http_build_query(['per_page' => 100]))
            ->assertOk()
            ->assertJsonCount(self::PAGE_SIZE, 'programs')
            ->assertJsonPath('meta.per_page', self::PAGE_SIZE);
    }

    public function test_list_page_past_the_end_returns_empty_programs(): void
    {
        Program::factory()->count(16)->create();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL.'?'.http_build_query(['page' => 999]))
            ->assertOk()
            ->assertJsonPath('programs', [])
            ->assertJsonPath('meta', ['current_page' => 999, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16]);
    }

    public function test_list_returns_exact_program_shape(): void
    {
        $program = Program::factory()->create(self::BSIT_PROGRAM);

        $listResponse = $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertExactJson([
                'programs' => [[
                    'id' => $program->id,
                    'code' => 'BSIT',
                    'name' => self::BSIT_PROGRAM['name'],
                    'stage' => 'college',
                    'description' => null,
                    'is_active' => true,
                    'created_at' => $program->created_at->toJSON(),
                    'updated_at' => $program->updated_at->toJSON(),
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => self::PAGE_SIZE, 'total' => 1],
            ]);

        $this->assertSame(self::PROGRAM_KEYS, array_keys($listResponse->json('programs.0')));
    }

    public function test_list_orders_by_code(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['ABM', 'BSBA', 'BSIT', 'STEM'], $this->fetchProgramCodes([]));
    }

    public function test_search_by_code_is_case_insensitive(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['BSIT'], $this->fetchProgramCodes(['search' => 'bsit']));
        $this->assertSame(['STEM'], $this->fetchProgramCodes(['search' => 'STEM']));
        $this->assertSame(['BSBA', 'BSIT'], $this->fetchProgramCodes(['search' => 'bs']));
    }

    public function test_search_by_name(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['ABM', 'BSBA'], $this->fetchProgramCodes(['search' => 'business']));
        $this->assertSame(['BSIT', 'STEM'], $this->fetchProgramCodes(['search' => 'technology']));
    }

    public function test_search_words_must_all_match(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['BSIT'], $this->fetchProgramCodes(['search' => 'information technology']));
        $this->assertSame(['BSBA'], $this->fetchProgramCodes(['search' => 'science business']));
        $this->assertSame(['STEM'], $this->fetchProgramCodes(['search' => '  stem   science  ']));
    }

    public function test_empty_search_returns_all(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['ABM', 'BSBA', 'BSIT', 'STEM'], $this->fetchProgramCodes(['search' => '']));
        $this->assertSame(['ABM', 'BSBA', 'BSIT', 'STEM'], $this->fetchProgramCodes(['search' => '  ']));
    }

    public function test_filter_by_stage(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['ABM', 'STEM'], $this->fetchProgramCodes(['stage' => 'shs']));
        $this->assertSame(['BSBA', 'BSIT'], $this->fetchProgramCodes(['stage' => 'college']));
    }

    public function test_filter_by_status(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['BSIT', 'STEM'], $this->fetchProgramCodes(['status' => 'active']));
        $this->assertSame(['ABM', 'BSBA'], $this->fetchProgramCodes(['status' => 'inactive']));
    }

    public function test_list_includes_inactive_programs_by_default(): void
    {
        $this->createFixturePrograms();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertJsonCount(4, 'programs')
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('programs.0.code', 'ABM')
            ->assertJsonPath('programs.0.is_active', false);
    }

    public function test_filters_and_search_combine(): void
    {
        $this->createFixturePrograms();

        $this->assertSame(['BSBA'], $this->fetchProgramCodes(['stage' => 'college', 'status' => 'inactive']));
        $this->assertSame(['STEM'], $this->fetchProgramCodes(['search' => 'science', 'stage' => 'shs']));
        $this->assertSame([], $this->fetchProgramCodes(['search' => 'business', 'status' => 'active']));
    }

    public function test_list_rejects_invalid_query_params(): void
    {
        $invalidQueries = [
            'stage' => [['stage' => 'jhs'], ['stage' => 'SHS'], ['stage' => ['shs']]],
            'status' => [['status' => 'all'], ['status' => ['active']]],
            'search' => [['search' => ['x']], ['search' => str_repeat('s', 256)]],
        ];

        foreach ($invalidQueries as $invalidKey => $listQueries) {
            $this->assertQueriesRejected($invalidKey, $listQueries);
        }
    }

    public function test_search_with_sql_metacharacters_is_safe(): void
    {
        $this->createFixturePrograms();

        $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL.'?'.http_build_query(['search' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertJsonPath('programs', []);
    }

    private function assertQueriesRejected(string $invalidKey, array $listQueries): void
    {
        foreach ($listQueries as $listQuery) {
            $this->withToken($this->adminToken)
                ->getJson(self::PROGRAMS_URL.'?'.http_build_query($listQuery))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$invalidKey]);
        }
    }

    private function createFixturePrograms(): void
    {
        Program::factory()->create(self::BSIT_PROGRAM);
        Program::factory()->create(self::STEM_PROGRAM);
        Program::factory()->inactive()->create(self::ABM_PROGRAM);
        Program::factory()->inactive()->create(self::BSBA_PROGRAM);
    }

    private function fetchProgramCodes(array $listQuery): array
    {
        $listResponse = $this->withToken($this->adminToken)
            ->getJson(self::PROGRAMS_URL.'?'.http_build_query($listQuery))
            ->assertOk();

        return array_column($listResponse->json('programs'), 'code');
    }

    private function createActingAdmin(): User
    {
        return User::factory()->admin()->create(self::ACTING_ADMIN);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
