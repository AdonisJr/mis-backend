<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GradeLevelTest extends TestCase
{
    use RefreshDatabase;

    private const GRADE_LEVELS_URL = '/api/mis/grade-levels';

    private const TOKEN_NAME = 'test';

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const GRADE_LEVEL_KEYS = ['id', 'name', 'stage', 'sort_order'];

    private const EXPECTED_GRADE_LEVELS = [
        'Grade 7' => GradeLevel::STAGE_JHS,
        'Grade 8' => GradeLevel::STAGE_JHS,
        'Grade 9' => GradeLevel::STAGE_JHS,
        'Grade 10' => GradeLevel::STAGE_JHS,
        'Grade 11' => GradeLevel::STAGE_SHS,
        'Grade 12' => GradeLevel::STAGE_SHS,
        '1st Year College' => GradeLevel::STAGE_COLLEGE,
        '2nd Year College' => GradeLevel::STAGE_COLLEGE,
        '3rd Year College' => GradeLevel::STAGE_COLLEGE,
        '4th Year College' => GradeLevel::STAGE_COLLEGE,
    ];

    public function test_grade_levels_table_has_name_sort_order_and_stage_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('grade_levels', ['id', 'name', 'sort_order', 'stage']));
    }

    public function test_grade_levels_hold_exactly_ten_levels_in_order(): void
    {
        $this->assertSame(10, GradeLevel::count());
        $this->assertSame(array_keys(self::EXPECTED_GRADE_LEVELS), GradeLevel::orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(range(1, 10), GradeLevel::orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_grade_levels_have_expected_stages(): void
    {
        $this->assertSame(self::EXPECTED_GRADE_LEVELS, GradeLevel::orderBy('sort_order')->pluck('stage', 'name')->all());
        $this->assertSame(4, GradeLevel::where('stage', GradeLevel::STAGE_JHS)->count());
        $this->assertSame(2, GradeLevel::where('stage', GradeLevel::STAGE_SHS)->count());
        $this->assertSame(4, GradeLevel::where('stage', GradeLevel::STAGE_COLLEGE)->count());
        $this->assertSame(0, GradeLevel::whereNotIn('stage', GradeLevel::STAGES)->count());
    }

    public function test_grade_levels_exist_without_running_seeder(): void
    {
        $this->assertSame(0, User::count());
        $this->assertSame(0, Role::count());
        $this->assertSame(0, Program::count());
        $this->assertSame(10, GradeLevel::count());
    }

    public function test_stage_constants_hold_lowercase_keys(): void
    {
        $this->assertSame(['jhs', 'shs', 'college'], GradeLevel::STAGES);
        $this->assertSame(['shs', 'college'], GradeLevel::PROGRAM_STAGES);
    }

    public function test_admin_lists_grade_levels_in_order(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $expectedGradeLevels = GradeLevel::orderBy('sort_order')->get()->map(fn (GradeLevel $gradeLevel) => [
            'id' => $gradeLevel->id,
            'name' => $gradeLevel->name,
            'stage' => $gradeLevel->stage,
            'sort_order' => $gradeLevel->sort_order,
        ])->all();

        $listResponse = $this->withToken($adminToken)->getJson(self::GRADE_LEVELS_URL)
            ->assertOk()
            ->assertExactJson(['grade_levels' => $expectedGradeLevels])
            ->assertJsonMissingPath('meta');

        $this->assertSame(self::GRADE_LEVEL_KEYS, array_keys($listResponse->json('grade_levels.0')));
        $this->assertSame(array_keys(self::EXPECTED_GRADE_LEVELS), array_column($listResponse->json('grade_levels'), 'name'));
    }

    public function test_registrar_lists_grade_levels(): void
    {
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->getJson(self::GRADE_LEVELS_URL)
            ->assertOk()
            ->assertJsonCount(10, 'grade_levels')
            ->assertJsonPath('grade_levels.4.name', 'Grade 11')
            ->assertJsonPath('grade_levels.4.stage', 'shs');
    }

    public function test_grade_levels_list_is_not_paginated(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());

        $this->withToken($adminToken)
            ->getJson(self::GRADE_LEVELS_URL.'?'.http_build_query(['page' => 2]))
            ->assertOk()
            ->assertJsonCount(10, 'grade_levels')
            ->assertJsonMissingPath('meta');
    }

    public function test_guest_gets_401_on_grade_levels(): void
    {
        $this->getJson(self::GRADE_LEVELS_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_user_without_role_gets_403_on_grade_levels(): void
    {
        $roleLessUser = User::factory()->create(['email' => 'norole@school.test']);

        $this->withToken($this->createTokenFor($roleLessUser))
            ->getJson(self::GRADE_LEVELS_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_grade_levels_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::GRADE_LEVELS_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    public function test_grade_levels_have_no_write_routes(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());
        $gradeSeven = GradeLevel::where('name', 'Grade 7')->firstOrFail();
        $gradeSevenUrl = self::GRADE_LEVELS_URL.'/'.$gradeSeven->id;

        $this->withToken($adminToken)
            ->postJson(self::GRADE_LEVELS_URL, ['name' => 'Grade 13', 'sort_order' => 11, 'stage' => 'shs'])
            ->assertStatus(405);
        $this->withToken($adminToken)->putJson($gradeSevenUrl, ['name' => 'Grade 0'])->assertNotFound();
        $this->withToken($adminToken)->patchJson($gradeSevenUrl, ['name' => 'Grade 0'])->assertNotFound();
        $this->withToken($adminToken)->deleteJson($gradeSevenUrl)->assertNotFound();

        $this->assertSame(10, GradeLevel::count());
        $reloadedGradeSeven = $gradeSeven->fresh();
        $this->assertSame('Grade 7', $reloadedGradeSeven->name);
        $this->assertSame('jhs', $reloadedGradeSeven->stage);
        $this->assertSame(1, $reloadedGradeSeven->sort_order);
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
