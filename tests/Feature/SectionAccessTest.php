<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectionAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS_URL = '/api/mis/sections';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const DEFAULT_CAPACITY = 40;

    private const SCHOOL_YEAR_2030 = ['name' => '2030-2031', 'start_date' => '2030-06-01', 'end_date' => '2031-03-31', 'term_type' => SchoolYear::TERM_TYPE_SEMESTER];

    private const GRADE_10 = 'Grade 10';

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private SchoolYear $schoolYear;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2030);
        $this->adminToken = $this->createAdminToken();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideSectionRoutes(): array
    {
        return [
            'list' => ['GET', self::SECTIONS_URL.'?school_year_id={schoolYear}'],
            ...self::provideAdminOnlySectionRoutes(),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideAdminOnlySectionRoutes(): array
    {
        return [
            'create' => ['POST', self::SECTIONS_URL],
            'update' => ['PUT', self::SECTIONS_URL.'/{section}'],
            'delete' => ['DELETE', self::SECTIONS_URL.'/{section}'],
        ];
    }

    public function test_admin_can_use_every_section_endpoint(): void
    {
        $sectionPayload = $this->buildSectionPayload(self::GRADE_10, ['name' => 'Access-Post']);
        $listUrl = self::SECTIONS_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, 'search' => 'Access-Post']);

        $createdSectionId = $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $sectionPayload)
            ->assertCreated()
            ->json('section.id');
        $sectionUrl = self::SECTIONS_URL.'/'.$createdSectionId;

        $this->withToken($this->adminToken)
            ->getJson($listUrl)
            ->assertOk()
            ->assertJsonPath('sections.0.name', 'Access-Post');
        $this->withToken($this->adminToken)
            ->putJson($sectionUrl, [...$sectionPayload, 'capacity' => 45])
            ->assertOk()
            ->assertJsonPath('section.capacity', 45);
        $this->withToken($this->adminToken)->deleteJson($sectionUrl)->assertNoContent();
        $this->withToken($this->adminToken)
            ->getJson($listUrl)
            ->assertOk()
            ->assertJsonPath('sections', []);
    }

    public function test_registrar_can_list_sections(): void
    {
        $this->createSection(self::GRADE_10, ['name' => 'Access-Post']);
        $listUrl = self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id;
        $adminListResponse = $this->withToken($this->adminToken)->getJson($listUrl)->assertOk();

        $this->app['auth']->forgetGuards();
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->getJson($listUrl)
            ->assertOk()
            ->assertJsonPath('sections', $adminListResponse->json('sections'))
            ->assertJsonPath('meta', $adminListResponse->json('meta'));
    }

    #[DataProvider('provideSectionRoutes')]
    public function test_guest_gets_401_on_section_routes(string $method, string $uriPattern): void
    {
        $targetSection = $this->createSection(self::GRADE_10, ['name' => 'Access-Post']);

        $this->json($method, $this->buildTargetUri($uriPattern, $targetSection), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetSection);
    }

    #[DataProvider('provideAdminOnlySectionRoutes')]
    public function test_registrar_gets_403_on_admin_only_section_routes(string $method, string $uriPattern): void
    {
        $targetSection = $this->createSection(self::GRADE_10, ['name' => 'Access-Post']);
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);

        $this->withToken($this->createTokenFor($registrarUser))
            ->json($method, $this->buildTargetUri($uriPattern, $targetSection), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetSection);
    }

    #[DataProvider('provideSectionRoutes')]
    public function test_user_without_role_gets_403_on_section_routes(string $method, string $uriPattern): void
    {
        $targetSection = $this->createSection(self::GRADE_10, ['name' => 'Access-Post']);
        $roleLessUser = User::factory()->create(['email' => 'norole@school.test']);

        $this->withToken($this->createTokenFor($roleLessUser))
            ->json($method, $this->buildTargetUri($uriPattern, $targetSection), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetSection);
    }

    public function test_auth_errors_come_before_validation(): void
    {
        $this->getJson(self::SECTIONS_URL)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $roleLessUser = User::factory()->create(['email' => 'norole@school.test']);
        $this->withToken($this->createTokenFor($roleLessUser))
            ->getJson(self::SECTIONS_URL)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->app['auth']->forgetGuards();
        $registrarUser = User::factory()->registrar()->create(['email' => 'registrar@school.test']);
        $this->withToken($this->createTokenFor($registrarUser))
            ->postJson(self::SECTIONS_URL, [])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_registrar_gets_403_for_missing_id(): void
    {
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create(['email' => 'registrar@school.test']));
        $missingSectionUrl = self::SECTIONS_URL.'/'.self::MISSING_ID;

        $this->withToken($registrarToken)
            ->putJson($missingSectionUrl, [])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
        $this->withToken($registrarToken)
            ->deleteJson($missingSectionUrl)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_section_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::SECTIONS_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    private function buildTargetUri(string $uriPattern, Section $targetSection): string
    {
        return strtr($uriPattern, ['{schoolYear}' => $targetSection->school_year_id, '{section}' => $targetSection->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        return $this->buildSectionPayload(self::GRADE_10, ['name' => 'Access-Post', 'capacity' => 45]);
    }

    private function assertTargetUnchanged(Section $targetSection): void
    {
        $reloadedSection = $targetSection->fresh();

        $this->assertNotNull($reloadedSection);
        $this->assertSame('Access-Post', $reloadedSection->name);
        $this->assertSame(self::DEFAULT_CAPACITY, $reloadedSection->capacity);
        $this->assertDatabaseCount('sections', 1);
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
