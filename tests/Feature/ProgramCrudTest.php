<?php

namespace Tests\Feature;

use App\Http\Controllers\ProgramController;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProgramCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMS_URL = '/api/mis/programs';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const PROGRAM_KEYS = ['id', 'code', 'name', 'stage', 'description', 'is_active', 'created_at', 'updated_at'];

    private const VALID_PAYLOAD = [
        'code' => 'BSED',
        'name' => 'Bachelor of Secondary Education',
        'stage' => GradeLevel::STAGE_COLLEGE,
        'description' => 'Teacher education program',
    ];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    private const ABM_PROGRAM = ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'stage' => GradeLevel::STAGE_SHS];

    private const BSIT_PROGRAM = ['code' => 'BSIT', 'name' => 'Bachelor of Science in Information Technology', 'stage' => GradeLevel::STAGE_COLLEGE];

    public function test_programs_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('programs', self::PROGRAM_KEYS));

        $programColumns = collect(Schema::getColumns('programs'))->keyBy('name');
        foreach (['code', 'name', 'stage', 'is_active'] as $requiredColumn) {
            $this->assertFalse($programColumns[$requiredColumn]['nullable'], $requiredColumn.' must not be nullable');
        }
        $this->assertTrue($programColumns['description']['nullable']);
    }

    public function test_is_active_defaults_to_true_in_the_database(): void
    {
        DB::table('programs')->insert(['code' => 'LEGACY', 'name' => 'Legacy Program', 'stage' => 'shs']);

        $this->assertTrue(Program::where('code', 'LEGACY')->first()->is_active);
    }

    public function test_program_code_has_unique_index(): void
    {
        DB::table('programs')->insert(['code' => 'BSIT', 'name' => 'First', 'stage' => 'college']);

        $this->expectException(QueryException::class);

        DB::table('programs')->insert(['code' => 'BSIT', 'name' => 'Second', 'stage' => 'college']);
    }

    public function test_admin_creates_program(): void
    {
        $adminToken = $this->createAdminToken();

        $createResponse = $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('program.code', 'BSED')
            ->assertJsonPath('program.name', self::VALID_PAYLOAD['name'])
            ->assertJsonPath('program.stage', 'college')
            ->assertJsonPath('program.description', self::VALID_PAYLOAD['description'])
            ->assertJsonPath('program.is_active', true);

        $this->assertSame(self::PROGRAM_KEYS, array_keys($createResponse->json('program')));
        $this->assertDatabaseHas('programs', ['code' => 'BSED', 'is_active' => true]);
        $this->assertDatabaseCount('programs', 1);
    }

    public function test_create_upper_cases_and_trims_code(): void
    {
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, 'code' => 'bsed'])
            ->assertCreated()
            ->assertJsonPath('program.code', 'BSED');
        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, [...self::ABM_PROGRAM, 'code' => ' abm ', 'stage' => 'shs'])
            ->assertCreated()
            ->assertJsonPath('program.code', 'ABM');

        $this->assertDatabaseHas('programs', ['code' => 'BSED']);
        $this->assertDatabaseHas('programs', ['code' => 'ABM']);
    }

    public function test_create_rejects_duplicate_code_in_any_case(): void
    {
        $adminToken = $this->createAdminToken();
        Program::factory()->create(['code' => 'BSED']);

        foreach (['BSED', 'bsed', 'BsEd', ' bsed '] as $duplicateCode) {
            $this->withToken($adminToken)
                ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, 'code' => $duplicateCode])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        }

        $this->assertDatabaseCount('programs', 1);
    }

    public function test_create_requires_code_name_and_stage(): void
    {
        $adminToken = $this->createAdminToken();

        foreach (['code', 'name', 'stage'] as $requiredField) {
            $this->withToken($adminToken)
                ->postJson(self::PROGRAMS_URL, Arr::except(self::VALID_PAYLOAD, $requiredField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
            $this->withToken($adminToken)
                ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, $requiredField => ''])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
        }

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_create_rejects_invalid_stage(): void
    {
        $adminToken = $this->createAdminToken();

        foreach (['jhs', 'JHS', 'SHS', 'College', 'elementary', ['shs']] as $invalidStage) {
            $this->withToken($adminToken)
                ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, 'stage' => $invalidStage])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['stage']);
        }

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_create_accepts_shs_and_college_stages(): void
    {
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::STEM_PROGRAM)
            ->assertCreated()
            ->assertJsonPath('program.stage', 'shs');
        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::BSIT_PROGRAM)
            ->assertCreated()
            ->assertJsonPath('program.stage', 'college');
    }

    public function test_create_without_description_stores_null(): void
    {
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, Arr::except(self::VALID_PAYLOAD, 'description'))
            ->assertCreated()
            ->assertJsonPath('program.description', null);
        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, [...self::STEM_PROGRAM, 'description' => ''])
            ->assertCreated()
            ->assertJsonPath('program.description', null);

        $this->assertNull(Program::where('code', 'BSED')->value('description'));
        $this->assertNull(Program::where('code', 'STEM')->value('description'));
    }

    public function test_create_rejects_oversized_fields(): void
    {
        $this->withToken($this->createAdminToken())
            ->postJson(self::PROGRAMS_URL, [
                ...self::VALID_PAYLOAD,
                'code' => str_repeat('A', 21),
                'name' => str_repeat('n', 256),
                'description' => str_repeat('d', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'description']);

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_create_accepts_max_length_fields(): void
    {
        $this->withToken($this->createAdminToken())
            ->postJson(self::PROGRAMS_URL, [
                ...self::VALID_PAYLOAD,
                'code' => str_repeat('A', 20),
                'name' => str_repeat('n', 255),
                'description' => str_repeat('d', 1000),
            ])
            ->assertCreated()
            ->assertJsonPath('program.code', str_repeat('A', 20));
    }

    public function test_create_rejects_non_string_values(): void
    {
        $adminToken = $this->createAdminToken();
        $nonStringCases = [
            ['code', 123],
            ['code', ['BSED']],
            ['name', ['x']],
            ['description', ['x']],
        ];

        foreach ($nonStringCases as [$fieldName, $nonStringValue]) {
            $this->withToken($adminToken)
                ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, $fieldName => $nonStringValue])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$fieldName]);
        }

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_create_ignores_is_active_and_id(): void
    {
        $createResponse = $this->withToken($this->createAdminToken())
            ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, 'is_active' => false, 'id' => 5000])
            ->assertCreated()
            ->assertJsonPath('program.is_active', true);

        $this->assertNotSame(5000, $createResponse->json('program.id'));
        $this->assertDatabaseMissing('programs', ['id' => 5000]);
    }

    public function test_admin_updates_program(): void
    {
        $program = Program::factory()->create(self::VALID_PAYLOAD);
        $updatePayload = [
            'code' => 'bsed-eng',
            'name' => 'Bachelor of Secondary Education Major in English',
            'stage' => 'college',
            'description' => 'English major',
        ];

        $updateResponse = $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id, $updatePayload)
            ->assertOk()
            ->assertJsonPath('program.id', $program->id)
            ->assertJsonPath('program.code', 'BSED-ENG')
            ->assertJsonPath('program.name', $updatePayload['name'])
            ->assertJsonPath('program.description', 'English major');

        $this->assertSame(self::PROGRAM_KEYS, array_keys($updateResponse->json('program')));
        $this->assertDatabaseHas('programs', [
            'id' => $program->id,
            'code' => 'BSED-ENG',
            'name' => $updatePayload['name'],
            'stage' => 'college',
            'description' => 'English major',
        ]);
    }

    public function test_update_allows_own_code_in_any_case(): void
    {
        $program = Program::factory()->create(self::VALID_PAYLOAD);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id, [...self::VALID_PAYLOAD, 'code' => 'bsed'])
            ->assertOk()
            ->assertJsonPath('program.code', 'BSED');
    }

    public function test_update_rejects_other_programs_code_in_any_case(): void
    {
        $adminToken = $this->createAdminToken();
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        Program::factory()->create(self::STEM_PROGRAM);
        $abmUrl = self::PROGRAMS_URL.'/'.$abmProgram->id;

        foreach (['STEM', 'stem'] as $takenCode) {
            $this->withToken($adminToken)
                ->putJson($abmUrl, [...self::ABM_PROGRAM, 'code' => $takenCode])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        }
        $this->assertSame('ABM', $abmProgram->fresh()->code);

        $this->withToken($adminToken)
            ->putJson($abmUrl, self::ABM_PROGRAM)
            ->assertOk()
            ->assertJsonPath('program.code', 'ABM');
    }

    public function test_update_rejects_invalid_fields(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::VALID_PAYLOAD);
        $invalidCases = [
            ['code', ''],
            ['name', ''],
            ['stage', 'jhs'],
            ['stage', 'elementary'],
        ];

        foreach ($invalidCases as [$fieldName, $invalidValue]) {
            $this->withToken($adminToken)
                ->putJson(self::PROGRAMS_URL.'/'.$program->id, [...self::VALID_PAYLOAD, $fieldName => $invalidValue])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$fieldName]);
        }

        $reloadedProgram = $program->fresh();
        $this->assertSame('BSED', $reloadedProgram->code);
        $this->assertSame(self::VALID_PAYLOAD['name'], $reloadedProgram->name);
        $this->assertSame('college', $reloadedProgram->stage);
    }

    public function test_update_can_change_stage(): void
    {
        $program = Program::factory()->create(self::STEM_PROGRAM);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id, [...self::STEM_PROGRAM, 'stage' => 'college'])
            ->assertOk()
            ->assertJsonPath('program.stage', 'college');

        $this->assertSame('college', $program->fresh()->stage);
    }

    public function test_update_description_null_clears_and_omitted_keeps(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::VALID_PAYLOAD);
        $programUrl = self::PROGRAMS_URL.'/'.$program->id;

        $this->withToken($adminToken)
            ->putJson($programUrl, Arr::except(self::VALID_PAYLOAD, 'description'))
            ->assertOk()
            ->assertJsonPath('program.description', 'Teacher education program');
        $this->assertSame('Teacher education program', $program->fresh()->description);

        $this->withToken($adminToken)
            ->putJson($programUrl, [...self::VALID_PAYLOAD, 'description' => null])
            ->assertOk()
            ->assertJsonPath('program.description', null);
        $this->assertNull($program->fresh()->description);
    }

    public function test_update_ignores_is_active(): void
    {
        $adminToken = $this->createAdminToken();
        $inactiveProgram = Program::factory()->inactive()->create(self::VALID_PAYLOAD);
        $activeProgram = Program::factory()->create(self::STEM_PROGRAM);

        $this->withToken($adminToken)
            ->putJson(self::PROGRAMS_URL.'/'.$inactiveProgram->id, [...self::VALID_PAYLOAD, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('program.is_active', false);
        $this->withToken($adminToken)
            ->putJson(self::PROGRAMS_URL.'/'.$activeProgram->id, [...self::STEM_PROGRAM, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('program.is_active', true);

        $this->assertFalse($inactiveProgram->fresh()->is_active);
        $this->assertTrue($activeProgram->fresh()->is_active);
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.self::MISSING_ID, [...self::VALID_PAYLOAD, 'code' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.self::MISSING_ID, self::VALID_PAYLOAD)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Program::class.'] '.self::MISSING_ID]);

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_admin_deletes_program(): void
    {
        $program = Program::factory()->create(self::VALID_PAYLOAD);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::PROGRAMS_URL.'/'.$program->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('programs', ['id' => $program->id]);
        $this->assertDatabaseCount('programs', 0);
    }

    public function test_delete_removes_inactive_program(): void
    {
        $program = Program::factory()->inactive()->create(self::VALID_PAYLOAD);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::PROGRAMS_URL.'/'.$program->id)
            ->assertNoContent();

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_delete_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::PROGRAMS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Program::class.'] '.self::MISSING_ID]);
    }

    public function test_deleted_program_code_can_be_reused(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::VALID_PAYLOAD);

        $this->withToken($adminToken)->deleteJson(self::PROGRAMS_URL.'/'.$program->id)->assertNoContent();
        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('program.code', 'BSED');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)->putJson(self::PROGRAMS_URL.'/abc', self::VALID_PAYLOAD)->assertNotFound();
        $this->withToken($adminToken)->putJson(self::PROGRAMS_URL.'/abc/deactivate')->assertNotFound();
        $this->withToken($adminToken)->putJson(self::PROGRAMS_URL.'/abc/reactivate')->assertNotFound();
        $this->withToken($adminToken)->deleteJson(self::PROGRAMS_URL.'/abc')->assertNotFound();
    }

    public function test_program_stage_matches_grade_levels_of_that_stage(): void
    {
        $adminToken = $this->createAdminToken();

        $stemStage = $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::STEM_PROGRAM)
            ->assertCreated()
            ->json('program.stage');
        $bsitStage = $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::BSIT_PROGRAM)
            ->assertCreated()
            ->json('program.stage');

        $this->assertSame(['Grade 11', 'Grade 12'], GradeLevel::where('stage', $stemStage)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(
            ['1st Year College', '2nd Year College', '3rd Year College', '4th Year College'],
            GradeLevel::where('stage', $bsitStage)->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertNotContains(GradeLevel::STAGE_JHS, GradeLevel::PROGRAM_STAGES);

        $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, [...self::VALID_PAYLOAD, 'stage' => 'jhs'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stage']);
        $this->assertSame(0, Program::where('stage', GradeLevel::STAGE_JHS)->count());
    }

    public function test_delete_program_with_sections_returns_422(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::STEM_PROGRAM);
        Section::factory()->create(['program_id' => $program->id]);

        $this->withToken($adminToken)
            ->deleteJson(self::PROGRAMS_URL.'/'.$program->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => ProgramController::SECTIONS_DELETE_MESSAGE]);

        $this->assertNotNull($program->fresh());
        $this->assertDatabaseCount('sections', 1);
        $listedCodes = $this->withToken($adminToken)->getJson(self::PROGRAMS_URL)->assertOk()->json('programs.*.code');
        $this->assertContains('STEM', $listedCodes);
    }

    public function test_delete_inactive_program_with_sections_returns_422(): void
    {
        $program = Program::factory()->inactive()->create(self::STEM_PROGRAM);
        Section::factory()->create(['program_id' => $program->id]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::PROGRAMS_URL.'/'.$program->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => ProgramController::SECTIONS_DELETE_MESSAGE]);

        $this->assertNotNull($program->fresh());
    }

    public function test_delete_program_without_sections_still_deletes(): void
    {
        $stemProgram = Program::factory()->create(self::STEM_PROGRAM);
        $abmProgram = Program::factory()->create(self::ABM_PROGRAM);
        Section::factory()->create(['program_id' => $stemProgram->id]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::PROGRAMS_URL.'/'.$abmProgram->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('programs', ['code' => 'ABM']);
        $this->assertNotNull($stemProgram->fresh());
    }

    public function test_program_with_sections_can_be_deactivated(): void
    {
        $program = Program::factory()->create(self::STEM_PROGRAM);
        $section = Section::factory()->create(['program_id' => $program->id]);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', false);

        $this->assertSame($program->id, $section->fresh()->program_id);
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor($this->createActingAdmin());
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
