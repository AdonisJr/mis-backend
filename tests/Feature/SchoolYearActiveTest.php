<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearActiveTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const SET_ACTIVE_URL = '/api/mis/school-years/set-active/';

    private const GET_ACTIVE_URL = '/api/mis/school-years/get-active';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const TERM_GET_ACTIVE_URL = '/api/mis/terms/get-active';

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

    public function test_set_active_activates_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.$schoolYear->id)
            ->assertOk()
            ->assertJsonPath('school_year.id', $schoolYear->id)
            ->assertJsonPath('school_year.is_active', true);

        $this->assertTrue($schoolYear->fresh()->is_active);
    }

    public function test_set_active_deactivates_all_others(): void
    {
        SchoolYear::factory()->active()->create();
        SchoolYear::factory()->create();
        $targetSchoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.$targetSchoolYear->id)
            ->assertOk();

        $this->assertSame([$targetSchoolYear->id], SchoolYear::where('is_active', true)->pluck('id')->all());
    }

    public function test_set_active_on_already_active_keeps_it_active(): void
    {
        $activeSchoolYear = SchoolYear::factory()->active()->create();
        SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.$activeSchoolYear->id)
            ->assertOk()
            ->assertJsonPath('school_year.is_active', true);

        $this->assertSame([$activeSchoolYear->id], SchoolYear::where('is_active', true)->pluck('id')->all());
    }

    public function test_set_active_missing_id_returns_404_and_changes_nothing(): void
    {
        config(['app.debug' => false]);
        $activeSchoolYear = SchoolYear::factory()->active()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.SchoolYear::class.'] '.self::MISSING_ID]);

        $this->assertTrue($activeSchoolYear->fresh()->is_active);
    }

    public function test_get_active_returns_active_for_admin(): void
    {
        $activeSchoolYear = SchoolYear::factory()->active()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
        ])->fresh();
        SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['school_year' => [
                'id' => $activeSchoolYear->id,
                'name' => '2026-2027',
                'start_date' => '2026-08-01',
                'end_date' => '2027-05-31',
                'description' => null,
                'term_type' => 'semester',
                'terms_count' => 0,
                'is_active' => true,
                'created_at' => $activeSchoolYear->created_at->toJSON(),
                'updated_at' => $activeSchoolYear->updated_at->toJSON(),
            ]]);
    }

    public function test_get_active_returns_active_for_registrar(): void
    {
        $activeSchoolYear = SchoolYear::factory()->active()->create();

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->getJson(self::GET_ACTIVE_URL)
            ->assertOk()
            ->assertJsonPath('school_year.id', $activeSchoolYear->id)
            ->assertJsonPath('school_year.is_active', true);
    }

    public function test_get_active_returns_null_when_none_active(): void
    {
        SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['school_year' => null]);

        $this->withToken($this->createTokenFor(User::factory()->registrar()->create()))
            ->getJson(self::GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['school_year' => null]);
    }

    public function test_get_active_reflects_latest_set_active(): void
    {
        $firstSchoolYear = SchoolYear::factory()->create();
        $secondSchoolYear = SchoolYear::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)->putJson(self::SET_ACTIVE_URL.$firstSchoolYear->id)->assertOk();
        $this->withToken($adminToken)->putJson(self::SET_ACTIVE_URL.$secondSchoolYear->id)->assertOk();

        $this->withToken($adminToken)
            ->getJson(self::GET_ACTIVE_URL)
            ->assertOk()
            ->assertJsonPath('school_year.id', $secondSchoolYear->id);
        $this->assertFalse($firstSchoolYear->fresh()->is_active);
    }

    public function test_set_active_of_another_year_clears_active_term(): void
    {
        $semesterYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $this->createTerm($semesterYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        $this->createTerm($semesterYear, self::SECOND_SEMESTER);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)
            ->putJson(self::SET_ACTIVE_URL.$trimesterYear->id)
            ->assertOk();

        $this->assertSame(0, Term::where('is_active', true)->count());
        $this->assertSame(2, Term::count());
        $this->withToken($adminToken)
            ->getJson(self::TERM_GET_ACTIVE_URL)
            ->assertOk()
            ->assertExactJson(['term' => null]);
    }

    public function test_set_active_of_same_year_keeps_active_term(): void
    {
        $semesterYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($semesterYear, [...self::FIRST_SEMESTER, 'is_active' => true]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.$semesterYear->id)
            ->assertOk();

        $this->assertTrue($activeTerm->fresh()->is_active);
    }

    public function test_set_active_missing_id_keeps_active_term(): void
    {
        config(['app.debug' => false]);
        $semesterYear = SchoolYear::factory()->active()->create(self::SEMESTER_YEAR);
        $activeTerm = $this->createTerm($semesterYear, [...self::FIRST_SEMESTER, 'is_active' => true]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.self::MISSING_ID)
            ->assertNotFound();

        $this->assertTrue($activeTerm->fresh()->is_active);
        $this->assertTrue($semesterYear->fresh()->is_active);
    }

    public function test_set_active_returns_term_type_and_terms_count(): void
    {
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($trimesterYear, self::FIRST_TRIMESTER);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SET_ACTIVE_URL.$trimesterYear->id)
            ->assertOk()
            ->assertJsonPath('school_year.term_type', 'trimester')
            ->assertJsonPath('school_year.terms_count', 1);
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
}
