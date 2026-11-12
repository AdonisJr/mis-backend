<?php

namespace Tests\Feature;

use App\Http\Controllers\TermController;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermActiveTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const TERMS_URL = '/api/mis/terms';

    private const TERM_SET_ACTIVE_URL = '/api/mis/terms/set-active/';

    private const TERM_GET_ACTIVE_URL = '/api/mis/terms/get-active';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const SEMESTER_YEAR = [
        'name' => '2030-2031',
        'start_date' => '2030-06-01',
        'end_date' => '2031-03-31',
        'term_type' => SchoolYear::TERM_TYPE_SEMESTER,
    ];

    private const TRIMESTER_YEAR = [
        'name' => '2031-2032',
        'start_date' => '2031-06-01',
        'end_date' => '2032-05-31',
        'term_type' => SchoolYear::TERM_TYPE_TRIMESTER,
    ];

    private const FIRST_SEMESTER = ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31'];

    private const SECOND_SEMESTER = ['term_number' => 2, 'start_date' => '2030-11-01', 'end_date' => '2031-03-31'];

    private const FIRST_TRIMESTER = ['term_number' => 1, 'start_date' => '2031-06-01', 'end_date' => '2031-09-30'];

    private const TERM_KEYS = [
        'id', 'school_year_id', 'term_number', 'label', 'start_date', 'end_date', 'is_active', 'created_at', 'updated_at',
    ];

    private const SCHOOL_YEAR_KEYS = [
        'id', 'name', 'start_date', 'end_date', 'description', 'term_type', 'terms_count', 'is_active', 'created_at', 'updated_at',
    ];

    public function test_admin_sets_term_active(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $setActiveResponse = $this->withToken($this->createAdminToken())
            ->putJson(self::TERM_SET_ACTIVE_URL.$term->id)
            ->assertOk()
            ->assertJsonPath('term.id', $term->id)
            ->assertJsonPath('term.is_active', true)
            ->assertJsonPath('term.label', '1st Semester');

        $this->assertSame(self::TERM_KEYS, array_keys($setActiveResponse->json('term')));
        $this->assertTrue($term->fresh()->is_active);
    }

    public function test_only_one_term_is_active(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $firstTerm = $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $secondTerm = $this->createTerm($schoolYear, self::SECOND_SEMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)->putJson(self::TERM_SET_ACTIVE_URL.$firstTerm->id)->assertOk();
        $this->withToken($adminToken)->putJson(self::TERM_SET_ACTIVE_URL.$secondTerm->id)->assertOk();

        $this->assertSame([$secondTerm->id], Term::where('is_active', true)->pluck('id')->all());
    }

    public function test_set_active_on_already_active_term_keeps_it_active(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERM_SET_ACTIVE_URL.$activeTerm->id)
            ->assertOk()
            ->assertJsonPath('term.is_active', true);

        $this->assertSame([$activeTerm->id], Term::where('is_active', true)->pluck('id')->all());
    }

    public function test_set_active_rejects_term_of_inactive_school_year(): void
    {
        $trimesterYear = SchoolYear::factory()->active()->create(self::TRIMESTER_YEAR);
        $trimesterTerm = $this->createTerm($trimesterYear, [...self::FIRST_TRIMESTER, 'is_active' => true]);
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $semesterTerm = $this->createTerm($semesterYear, self::FIRST_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERM_SET_ACTIVE_URL.$semesterTerm->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::INACTIVE_SCHOOL_YEAR_MESSAGE]);

        $this->assertFalse($semesterTerm->fresh()->is_active);
        $this->assertTrue($trimesterTerm->fresh()->is_active);
    }

    public function test_set_active_rejects_when_no_school_year_is_active(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERM_SET_ACTIVE_URL.$term->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::INACTIVE_SCHOOL_YEAR_MESSAGE]);

        $this->assertSame(0, Term::where('is_active', true)->count());
    }

    public function test_set_active_missing_term_returns_404_and_changes_nothing(): void
    {
        config(['app.debug' => false]);
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERM_SET_ACTIVE_URL.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Term::class.'] '.self::MISSING_ID]);

        $this->assertTrue($activeTerm->fresh()->is_active);
    }

    public function test_active_term_cannot_be_deleted(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->deleteJson(self::TERMS_URL.'/'.$activeTerm->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::ACTIVE_DELETE_MESSAGE]);

        $this->assertNotNull($activeTerm->fresh());
        $this->withToken($adminToken)
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertJsonPath('term.id', $activeTerm->id);
    }

    public function test_inactive_term_of_active_year_can_be_deleted(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        $inactiveTerm = $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::TERMS_URL.'/'.$inactiveTerm->id)
            ->assertNoContent();

        $this->assertNull($inactiveTerm->fresh());
        $this->assertTrue($activeTerm->fresh()->is_active);
    }

    public function test_get_active_returns_term_with_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR)->fresh();
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true])->fresh();
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $getActiveResponse = $this->withToken($this->createAdminToken())
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['term' => [
                'id' => $activeTerm->id,
                'school_year_id' => $schoolYear->id,
                'term_number' => 1,
                'label' => '1st Semester',
                'start_date' => '2030-06-01',
                'end_date' => '2030-10-31',
                'is_active' => true,
                'created_at' => $activeTerm->created_at->toJSON(),
                'updated_at' => $activeTerm->updated_at->toJSON(),
                'school_year' => [
                    'id' => $schoolYear->id,
                    'name' => '2030-2031',
                    'start_date' => '2030-06-01',
                    'end_date' => '2031-03-31',
                    'description' => null,
                    'term_type' => 'semester',
                    'terms_count' => 2,
                    'is_active' => true,
                    'created_at' => $schoolYear->created_at->toJSON(),
                    'updated_at' => $schoolYear->updated_at->toJSON(),
                ],
            ]]);

        $this->assertSame([...self::TERM_KEYS, 'school_year'], array_keys($getActiveResponse->json('term')));
        $this->assertSame(self::SCHOOL_YEAR_KEYS, array_keys($getActiveResponse->json('term.school_year')));
    }

    public function test_get_active_returns_term_for_registrar(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertJsonPath('term.id', $activeTerm->id)
            ->assertJsonPath('term.label', '1st Semester')
            ->assertJsonPath('term.school_year.id', $schoolYear->id);
    }

    public function test_get_active_returns_null_when_no_term_is_active(): void
    {
        $semesterYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $this->createTerm($semesterYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/set-active/'.$trimesterYear->id)
            ->assertOk();

        $this->withToken($adminToken)
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['term' => null]);

        $this->app['auth']->forgetGuards();

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['term' => null]);
    }

    public function test_old_year_terms_cannot_be_activated_after_year_switch(): void
    {
        $semesterYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $semesterTerm = $this->createTerm($semesterYear, self::FIRST_SEMESTER);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $trimesterTerm = $this->createTerm($trimesterYear, self::FIRST_TRIMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/set-active/'.$trimesterYear->id)
            ->assertOk();

        $this->withToken($adminToken)
            ->putJson(self::TERM_SET_ACTIVE_URL.$semesterTerm->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::INACTIVE_SCHOOL_YEAR_MESSAGE]);

        $this->withToken($adminToken)
            ->putJson(self::TERM_SET_ACTIVE_URL.$trimesterTerm->id)
            ->assertOk()
            ->assertJsonPath('term.is_active', true);

        $this->assertSame([$trimesterTerm->id], Term::where('is_active', true)->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $termAttributes
     */
    private function createTerm(SchoolYear $schoolYear, array $termAttributes): Term
    {
        return Term::factory()->for($schoolYear)->create($termAttributes);
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
