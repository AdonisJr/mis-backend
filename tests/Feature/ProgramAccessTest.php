<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgramAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PROGRAMS_URL = '/api/mis/programs';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const ACTING_ADMIN = ['name' => 'Acting Admin', 'email' => 'acting@admin.test'];

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const VALID_PAYLOAD = [
        'code' => 'BSED',
        'name' => 'Bachelor of Secondary Education',
        'stage' => GradeLevel::STAGE_COLLEGE,
        'description' => 'Teacher education program',
    ];

    private const STEM_PROGRAM = ['code' => 'STEM', 'name' => 'Science, Technology, Engineering and Mathematics', 'stage' => GradeLevel::STAGE_SHS];

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideProgramRoutes(): array
    {
        return [
            'list' => ['GET', self::PROGRAMS_URL],
            ...self::provideAdminOnlyProgramRoutes(),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAdminOnlyProgramRoutes(): array
    {
        return [
            'create' => ['POST', self::PROGRAMS_URL],
            'update' => ['PUT', self::PROGRAMS_URL.'/%d'],
            'deactivate' => ['PUT', self::PROGRAMS_URL.'/%d/deactivate'],
            'reactivate' => ['PUT', self::PROGRAMS_URL.'/%d/reactivate'],
            'delete' => ['DELETE', self::PROGRAMS_URL.'/%d'],
        ];
    }

    public function test_admin_can_use_every_program_endpoint(): void
    {
        $adminToken = $this->createTokenFor($this->createActingAdmin());

        $this->withToken($adminToken)->getJson(self::PROGRAMS_URL)->assertOk();

        $createdProgramId = $this->withToken($adminToken)
            ->postJson(self::PROGRAMS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->json('program.id');
        $programUrl = self::PROGRAMS_URL.'/'.$createdProgramId;

        $this->withToken($adminToken)
            ->putJson($programUrl, [...self::VALID_PAYLOAD, 'name' => 'Bachelor of Secondary Education Major in Math'])
            ->assertOk()
            ->assertJsonPath('program.name', 'Bachelor of Secondary Education Major in Math');
        $this->withToken($adminToken)
            ->putJson($programUrl.'/deactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', false);
        $this->withToken($adminToken)
            ->putJson($programUrl.'/reactivate')
            ->assertOk()
            ->assertJsonPath('program.is_active', true);
        $this->withToken($adminToken)->deleteJson($programUrl)->assertNoContent();

        $this->assertDatabaseCount('programs', 0);
    }

    public function test_registrar_can_list_programs(): void
    {
        Program::factory()->create(self::STEM_PROGRAM);
        $adminListResponse = $this->withToken($this->createTokenFor($this->createActingAdmin()))
            ->getJson(self::PROGRAMS_URL)
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->getJson(self::PROGRAMS_URL)
            ->assertOk()
            ->assertJsonPath('programs', $adminListResponse->json('programs'))
            ->assertJsonPath('meta', $adminListResponse->json('meta'));
    }

    #[DataProvider('provideProgramRoutes')]
    public function test_guest_gets_401_on_program_routes(string $method, string $uriPattern): void
    {
        $targetProgram = Program::factory()->create(self::STEM_PROGRAM);

        $this->json($method, sprintf($uriPattern, $targetProgram->id), self::VALID_PAYLOAD)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetProgram);
    }

    #[DataProvider('provideAdminOnlyProgramRoutes')]
    public function test_registrar_gets_403_on_admin_only_program_routes(string $method, string $uriPattern): void
    {
        $targetProgram = Program::factory()->create(self::STEM_PROGRAM);
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->json($method, sprintf($uriPattern, $targetProgram->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetProgram);
    }

    #[DataProvider('provideProgramRoutes')]
    public function test_user_without_role_gets_403_on_program_routes(string $method, string $uriPattern): void
    {
        $targetProgram = Program::factory()->create(self::STEM_PROGRAM);
        $roleLessUser = User::factory()->create(['email' => 'norole@school.test']);

        $this->withToken($this->createTokenFor($roleLessUser))
            ->json($method, sprintf($uriPattern, $targetProgram->id), self::VALID_PAYLOAD)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetProgram);
    }

    public function test_registrar_gets_403_for_missing_id(): void
    {
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create(['email' => 'registrar@school.test']));
        $missingProgramUrl = self::PROGRAMS_URL.'/'.self::MISSING_ID;

        foreach (['', '/deactivate', '/reactivate'] as $routeSuffix) {
            $this->withToken($registrarToken)
                ->putJson($missingProgramUrl.$routeSuffix, self::VALID_PAYLOAD)
                ->assertForbidden()
                ->assertExactJson(self::FORBIDDEN_BODY);
        }
        $this->withToken($registrarToken)
            ->deleteJson($missingProgramUrl)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_program_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::PROGRAMS_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    private function assertTargetUnchanged(Program $targetProgram): void
    {
        $reloadedProgram = $targetProgram->fresh();

        $this->assertNotNull($reloadedProgram);
        $this->assertSame('STEM', $reloadedProgram->code);
        $this->assertSame(self::STEM_PROGRAM['name'], $reloadedProgram->name);
        $this->assertSame('shs', $reloadedProgram->stage);
        $this->assertTrue($reloadedProgram->is_active);
        $this->assertDatabaseCount('programs', 1);
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
