<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TermAccessTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const TERMS_URL = '/api/mis/terms';

    private const TERM_SET_ACTIVE_URL = '/api/mis/terms/set-active/';

    private const TERM_GET_ACTIVE_URL = '/api/mis/terms/get-active';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const UNAUTHENTICATED_BODY = ['message' => 'Unauthenticated.'];

    private const FORBIDDEN_BODY = ['message' => 'Unauthorized'];

    private const SEMESTER_YEAR = [
        'name' => '2030-2031',
        'start_date' => '2030-06-01',
        'end_date' => '2031-03-31',
        'term_type' => SchoolYear::TERM_TYPE_SEMESTER,
    ];

    private const FIRST_SEMESTER = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_SEMESTER = ['term_number' => 2, 'start_date' => '2030-11-01', 'end_date' => '2031-03-31'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTermRoutes(): array
    {
        return [
            'list' => ['GET', self::SCHOOL_YEARS_URL.'/{schoolYear}/terms'],
            'get-active' => ['GET', self::TERM_GET_ACTIVE_URL],
            ...self::provideTermWriteRoutes(),
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTermWriteRoutes(): array
    {
        return [
            'create' => ['POST', self::SCHOOL_YEARS_URL.'/{schoolYear}/terms'],
            'update' => ['PUT', self::TERMS_URL.'/{term}'],
            'delete' => ['DELETE', self::TERMS_URL.'/{term}'],
            'set-active' => ['PUT', self::TERM_SET_ACTIVE_URL.'{term}'],
        ];
    }

    public function test_admin_can_use_every_term_endpoint(): void
    {
        $firstTerm = $this->createTargetTerm();
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)->getJson($this->buildTermsUrl($firstTerm->school_year_id))->assertOk();
        $secondTermId = $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($firstTerm->school_year_id), self::SECOND_SEMESTER)
            ->assertCreated()
            ->json('term.id');
        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$secondTermId, [...self::SECOND_SEMESTER, 'end_date' => '2031-03-30'])
            ->assertOk();
        $this->withToken($adminToken)->putJson(self::TERM_SET_ACTIVE_URL.$secondTermId)->assertOk();
        $this->withToken($adminToken)
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertJsonPath('term.id', $secondTermId);
        $this->withToken($adminToken)->deleteJson(self::TERMS_URL.'/'.$firstTerm->id)->assertNoContent();
        $this->withToken($adminToken)->deleteJson(self::TERMS_URL.'/'.$secondTermId)->assertUnprocessable();
    }

    public function test_registrar_can_read_terms(): void
    {
        $term = $this->createTargetTerm();
        $listUrl = $this->buildTermsUrl($term->school_year_id);
        $adminToken = $this->createAdminToken();

        $adminListResponse = $this->withToken($adminToken)->getJson($listUrl)->assertOk();
        $adminGetActiveResponse = $this->withToken($adminToken)->getJson(self::TERM_GET_ACTIVE_URL)->assertOk();

        $this->app['auth']->forgetGuards();
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create());

        $this->withToken($registrarToken)
            ->getJson($listUrl)
            ->assertOk()
            ->assertExactJson($adminListResponse->json());
        $this->withToken($registrarToken)
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson($adminGetActiveResponse->json());
    }

    #[DataProvider('provideTermRoutes')]
    public function test_guest_gets_401_on_term_routes(string $method, string $uriPattern): void
    {
        $targetTerm = $this->createTargetTerm();

        $this->json($method, $this->buildTargetUri($uriPattern, $targetTerm), self::SECOND_SEMESTER)
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetTerm);
    }

    #[DataProvider('provideTermWriteRoutes')]
    public function test_registrar_gets_403_on_term_write_routes(string $method, string $uriPattern): void
    {
        $targetTerm = $this->createTargetTerm();

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->json($method, $this->buildTargetUri($uriPattern, $targetTerm), self::SECOND_SEMESTER)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetTerm);
    }

    #[DataProvider('provideTermRoutes')]
    public function test_user_without_role_gets_403_on_term_routes(string $method, string $uriPattern): void
    {
        $targetTerm = $this->createTargetTerm();

        $this->withToken($this->createTokenFor(User::factory()->create()))
            ->json($method, $this->buildTargetUri($uriPattern, $targetTerm), self::SECOND_SEMESTER)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetTerm);
    }

    public function test_registrar_gets_403_for_missing_ids(): void
    {
        $registrarToken = $this->createTokenFor(User::factory()->registrar()->create());

        $this->withToken($registrarToken)
            ->postJson($this->buildTermsUrl(self::MISSING_ID), self::SECOND_SEMESTER)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
        $this->withToken($registrarToken)
            ->putJson(self::TERMS_URL.'/'.self::MISSING_ID, self::SECOND_SEMESTER)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
        $this->withToken($registrarToken)
            ->deleteJson(self::TERMS_URL.'/'.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
        $this->withToken($registrarToken)
            ->putJson(self::TERM_SET_ACTIVE_URL.self::MISSING_ID)
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);
    }

    public function test_term_route_without_accept_header_returns_401_json(): void
    {
        $this->get(self::TERM_GET_ACTIVE_URL)
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(self::UNAUTHENTICATED_BODY);
    }

    private function createTargetTerm(): Term
    {
        return $this->createTerm(SchoolYear::factory()->active()->create(self::SEMESTER_YEAR), self::FIRST_SEMESTER);
    }

    /**
     * @param  array<string, mixed>  $termAttributes
     */
    private function createTerm(SchoolYear $schoolYear, array $termAttributes): Term
    {
        return Term::factory()->for($schoolYear)->create($termAttributes);
    }

    private function buildTermsUrl(int $schoolYearId): string
    {
        return self::SCHOOL_YEARS_URL.'/'.$schoolYearId.'/terms';
    }

    private function buildTargetUri(string $uriPattern, Term $targetTerm): string
    {
        return strtr($uriPattern, ['{schoolYear}' => $targetTerm->school_year_id, '{term}' => $targetTerm->id]);
    }

    private function assertTargetUnchanged(Term $targetTerm): void
    {
        $this->assertDatabaseCount('terms', 1);
        $this->assertDatabaseCount('school_years', 1);

        $freshTerm = $targetTerm->fresh();
        $this->assertSame(1, $freshTerm->term_number);
        $this->assertSame('2030-10-31', $freshTerm->end_date->toDateString());
        $this->assertFalse($freshTerm->is_active);
    }

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor(User::factory()->admin()->create());
    }
}
