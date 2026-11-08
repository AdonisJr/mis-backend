<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramStatusTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMS_URL = '/api/mis/programs';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const PROGRAM_KEYS = ['id', 'code', 'name', 'stage', 'description', 'is_active', 'created_at', 'updated_at'];

    private const GAS_PROGRAM = ['code' => 'GAS', 'name' => 'General Academic Strand', 'stage' => GradeLevel::STAGE_SHS];

    public function test_admin_deactivates_program(): void
    {
        $program = Program::factory()->create(self::GAS_PROGRAM);

        $deactivateResponse = $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', false)
            ->assertJsonPath('program.code', 'GAS')
            ->assertJsonPath('program.name', self::GAS_PROGRAM['name'])
            ->assertJsonPath('program.stage', 'shs');

        $this->assertSame(self::PROGRAM_KEYS, array_keys($deactivateResponse->json('program')));
        $this->assertFalse($program->fresh()->is_active);
    }

    public function test_admin_reactivates_program(): void
    {
        $program = Program::factory()->inactive()->create(self::GAS_PROGRAM);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id.'/reactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', true);

        $this->assertTrue($program->fresh()->is_active);
    }

    public function test_deactivate_is_idempotent(): void
    {
        $program = Program::factory()->inactive()->create(self::GAS_PROGRAM);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', false);

        $this->assertFalse($program->fresh()->is_active);
    }

    public function test_reactivate_is_idempotent(): void
    {
        $program = Program::factory()->create(self::GAS_PROGRAM);

        $this->withToken($this->createAdminToken())
            ->putJson(self::PROGRAMS_URL.'/'.$program->id.'/reactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', true);

        $this->assertTrue($program->fresh()->is_active);
    }

    public function test_status_actions_ignore_request_body(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::GAS_PROGRAM);
        $programUrl = self::PROGRAMS_URL.'/'.$program->id;

        $this->withToken($adminToken)
            ->putJson($programUrl.'/deactivate', ['is_active' => true, 'code' => 'HACK', 'name' => 'Changed'])
            ->assertOk()
            ->assertJsonPath('program.is_active', false)
            ->assertJsonPath('program.code', 'GAS')
            ->assertJsonPath('program.name', self::GAS_PROGRAM['name']);
        $reloadedProgram = $program->fresh();
        $this->assertFalse($reloadedProgram->is_active);
        $this->assertSame('GAS', $reloadedProgram->code);
        $this->assertSame(self::GAS_PROGRAM['name'], $reloadedProgram->name);

        $this->withToken($adminToken)
            ->putJson($programUrl.'/reactivate', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('program.is_active', true);
        $this->assertTrue($program->fresh()->is_active);
    }

    public function test_deactivated_program_stays_listed(): void
    {
        $adminToken = $this->createAdminToken();
        $program = Program::factory()->create(self::GAS_PROGRAM);

        $this->withToken($adminToken)->putJson(self::PROGRAMS_URL.'/'.$program->id.'/deactivate')->assertOk();

        $this->withToken($adminToken)
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertJsonPath('programs.0.code', 'GAS')
            ->assertJsonPath('programs.0.is_active', false)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_status_routes_missing_id_return_404(): void
    {
        config(['app.debug' => false]);
        $adminToken = $this->createAdminToken();

        foreach (['/deactivate', '/reactivate'] as $statusSuffix) {
            $this->withToken($adminToken)
                ->putJson(self::PROGRAMS_URL.'/'.self::MISSING_ID.$statusSuffix)
                ->assertNotFound()
                ->assertExactJson(['message' => 'No query results for model ['.Program::class.'] '.self::MISSING_ID]);
        }
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
