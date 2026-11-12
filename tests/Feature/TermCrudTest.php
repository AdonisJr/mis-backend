<?php

namespace Tests\Feature;

use App\Http\Controllers\TermController;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TermCrudTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const TERMS_URL = '/api/mis/terms';

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

    private const SECOND_TRIMESTER = ['term_number' => 2, 'start_date' => '2031-10-01', 'end_date' => '2032-01-31'];

    private const THIRD_TRIMESTER = ['term_number' => 3, 'start_date' => '2032-02-01', 'end_date' => '2032-05-31'];

    private const TERM_KEYS = [
        'id', 'school_year_id', 'term_number', 'label', 'start_date', 'end_date', 'is_active', 'created_at', 'updated_at',
    ];

    public function test_terms_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('terms', [
            'id', 'school_year_id', 'term_number', 'start_date', 'end_date', 'is_active', 'created_at', 'updated_at',
        ]));
        $this->assertSame('date', Schema::getColumnType('terms', 'start_date'));
        $this->assertSame('date', Schema::getColumnType('terms', 'end_date'));

        $termColumns = collect(Schema::getColumns('terms'))->keyBy('name');
        foreach (['school_year_id', 'term_number', 'start_date', 'end_date', 'is_active'] as $requiredColumn) {
            $this->assertFalse($termColumns[$requiredColumn]['nullable'], $requiredColumn);
        }
    }

    public function test_is_active_defaults_to_false_in_the_database(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);

        DB::table('terms')->insert(['school_year_id' => $schoolYear->id, ...self::FIRST_SEMESTER]);

        $this->assertFalse((bool) DB::table('terms')->value('is_active'));
    }

    public function test_term_number_is_unique_per_school_year_in_the_database(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        DB::table('terms')->insert(['school_year_id' => $semesterYear->id, ...self::FIRST_SEMESTER]);
        DB::table('terms')->insert(['school_year_id' => $trimesterYear->id, ...self::FIRST_TRIMESTER]);
        $this->assertDatabaseCount('terms', 2);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('terms')->insert(['school_year_id' => $semesterYear->id, ...self::SECOND_SEMESTER, 'term_number' => 1]);
    }

    public function test_term_requires_existing_school_year_in_the_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('terms')->insert(['school_year_id' => self::MISSING_ID, ...self::FIRST_SEMESTER]);
    }

    public function test_admin_lists_terms_ordered_by_number(): void
    {
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($trimesterYear, self::THIRD_TRIMESTER);
        $this->createTerm($trimesterYear, self::FIRST_TRIMESTER);
        $this->createTerm($trimesterYear, self::SECOND_TRIMESTER);
        Term::factory()->create();

        $listResponse = $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl($trimesterYear->id))
            ->assertOk()
            ->assertJsonCount(3, 'terms')
            ->assertJsonMissingPath('meta');

        $this->assertSame([1, 2, 3], array_column($listResponse->json('terms'), 'term_number'));
        $this->assertSame(
            [$trimesterYear->id, $trimesterYear->id, $trimesterYear->id],
            array_column($listResponse->json('terms'), 'school_year_id'),
        );
    }

    public function test_list_returns_exact_term_shape(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_SEMESTER)->fresh();

        $listResponse = $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk()
            ->assertExactJson(['terms' => [[
                'id' => $term->id,
                'school_year_id' => $schoolYear->id,
                'term_number' => 1,
                'label' => '1st Semester',
                'start_date' => '2030-06-01',
                'end_date' => '2030-10-31',
                'is_active' => false,
                'created_at' => $term->created_at->toJSON(),
                'updated_at' => $term->updated_at->toJSON(),
            ]]])
            ->assertJsonMissingPath('terms.0.school_year');

        $this->assertSame(self::TERM_KEYS, array_keys($listResponse->json('terms.0')));
    }

    public function test_semester_terms_are_labelled(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $listResponse = $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk();

        $this->assertSame(['1st Semester', '2nd Semester'], array_column($listResponse->json('terms'), 'label'));
    }

    public function test_trimester_terms_are_labelled(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_TRIMESTER);
        $this->createTerm($schoolYear, self::SECOND_TRIMESTER);
        $this->createTerm($schoolYear, self::THIRD_TRIMESTER);

        $listResponse = $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk();

        $this->assertSame(
            ['1st Trimester', '2nd Trimester', '3rd Trimester'],
            array_column($listResponse->json('terms'), 'label'),
        );
    }

    public function test_list_returns_empty_array_for_year_without_terms(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);

        $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk()
            ->assertExactJson(['terms' => []]);
    }

    public function test_list_missing_school_year_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createAdminToken())
            ->getJson($this->buildTermsUrl(self::MISSING_ID))
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.SchoolYear::class.'] '.self::MISSING_ID]);
    }

    public function test_admin_creates_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $createResponse = $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), self::FIRST_SEMESTER)
            ->assertCreated()
            ->assertJsonPath('term.school_year_id', $schoolYear->id)
            ->assertJsonPath('term.term_number', 1)
            ->assertJsonPath('term.label', '1st Semester')
            ->assertJsonPath('term.start_date', '2030-06-01')
            ->assertJsonPath('term.end_date', '2030-10-31')
            ->assertJsonPath('term.is_active', false);

        $this->assertSame(self::TERM_KEYS, array_keys($createResponse->json('term')));
        $this->assertDatabaseHas('terms', [
            'id' => $createResponse->json('term.id'),
            'school_year_id' => $schoolYear->id,
            'term_number' => 1,
            'is_active' => false,
        ]);

        $this->withToken($adminToken)
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk()
            ->assertJsonPath('terms.0.label', '1st Semester');
    }

    public function test_create_accepts_highest_number_of_each_type(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($semesterYear->id), self::SECOND_SEMESTER)
            ->assertCreated()
            ->assertJsonPath('term.label', '2nd Semester');

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($trimesterYear->id), self::THIRD_TRIMESTER)
            ->assertCreated()
            ->assertJsonPath('term.label', '3rd Trimester')
            ->assertJsonPath('term.end_date', '2032-05-31');
    }

    public function test_create_ignores_is_active_and_school_year_id(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);

        $this->withToken($this->createAdminToken())
            ->postJson($this->buildTermsUrl($semesterYear->id), [
                ...self::FIRST_SEMESTER,
                'is_active' => true,
                'school_year_id' => $trimesterYear->id,
            ])
            ->assertCreated()
            ->assertJsonPath('term.is_active', false)
            ->assertJsonPath('term.school_year_id', $semesterYear->id);

        $this->assertSame(0, $trimesterYear->terms()->count());
        $this->assertFalse($semesterYear->terms()->firstOrFail()->is_active);
    }

    public function test_create_on_missing_school_year_returns_404(): void
    {
        config(['app.debug' => false]);
        $adminToken = $this->createAdminToken();

        foreach ([self::FIRST_SEMESTER, []] as $createPayload) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl(self::MISSING_ID), $createPayload)
                ->assertNotFound()
                ->assertExactJson(['message' => 'No query results for model ['.SchoolYear::class.'] '.self::MISSING_ID]);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_admin_updates_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $updateResponse = $this->withToken($this->createAdminToken())
            ->putJson(self::TERMS_URL.'/'.$term->id, [...self::FIRST_SEMESTER, 'end_date' => '2030-10-15'])
            ->assertOk()
            ->assertJsonPath('term.id', $term->id)
            ->assertJsonPath('term.end_date', '2030-10-15')
            ->assertJsonPath('term.label', '1st Semester');

        $this->assertSame(self::TERM_KEYS, array_keys($updateResponse->json('term')));
        $this->assertSame('2030-10-15', $term->fresh()->end_date->toDateString());
    }

    public function test_update_ignores_is_active_and_school_year_id(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $term = $this->createTerm($semesterYear, self::FIRST_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERMS_URL.'/'.$term->id, [
                ...self::FIRST_SEMESTER,
                'is_active' => true,
                'school_year_id' => $trimesterYear->id,
            ])
            ->assertOk()
            ->assertJsonPath('term.is_active', false)
            ->assertJsonPath('term.school_year_id', $semesterYear->id);

        $this->assertFalse($term->fresh()->is_active);
        $this->assertSame($semesterYear->id, $term->fresh()->school_year_id);
    }

    public function test_update_missing_term_returns_404(): void
    {
        config(['app.debug' => false]);
        $adminToken = $this->createAdminToken();

        foreach ([self::FIRST_SEMESTER, []] as $updatePayload) {
            $this->withToken($adminToken)
                ->putJson(self::TERMS_URL.'/'.self::MISSING_ID, $updatePayload)
                ->assertNotFound()
                ->assertExactJson(['message' => 'No query results for model ['.Term::class.'] '.self::MISSING_ID]);
        }
    }

    public function test_admin_deletes_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $secondTerm = $this->createTerm($schoolYear, self::SECOND_SEMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->deleteJson(self::TERMS_URL.'/'.$secondTerm->id)
            ->assertNoContent();

        $listResponse = $this->withToken($adminToken)
            ->getJson($this->buildTermsUrl($schoolYear->id))
            ->assertOk();

        $this->assertSame(['1st Semester'], array_column($listResponse->json('terms'), 'label'));
        $this->assertDatabaseMissing('terms', ['id' => $secondTerm->id]);
    }

    public function test_delete_term_with_enrollments_returns_422(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        Enrollment::factory()->shs()->create(['term_id' => $term->id]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::TERMS_URL.'/'.$term->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::ENROLLMENTS_DELETE_MESSAGE]);

        $this->assertDatabaseHas('terms', ['id' => $term->id]);
    }

    public function test_delete_active_term_with_enrollments_reports_active_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $term = $this->createTerm($schoolYear, [...self::FIRST_SEMESTER, 'is_active' => true]);
        Enrollment::factory()->shs()->create(['term_id' => $term->id]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::TERMS_URL.'/'.$term->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => TermController::ACTIVE_DELETE_MESSAGE]);

        $this->assertDatabaseHas('terms', ['id' => $term->id]);
    }

    public function test_delete_missing_term_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createAdminToken())
            ->deleteJson(self::TERMS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Term::class.'] '.self::MISSING_ID]);
    }

    public function test_non_numeric_ids_return_404(): void
    {
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)->getJson(self::SCHOOL_YEARS_URL.'/abc/terms')->assertNotFound();
        $this->withToken($adminToken)->postJson(self::SCHOOL_YEARS_URL.'/abc/terms', self::FIRST_SEMESTER)->assertNotFound();
        $this->withToken($adminToken)->putJson(self::TERMS_URL.'/abc', self::FIRST_SEMESTER)->assertNotFound();
        $this->withToken($adminToken)->deleteJson(self::TERMS_URL.'/abc')->assertNotFound();
        $this->withToken($adminToken)->putJson(self::TERMS_URL.'/set-active/abc')->assertNotFound();
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

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }

    private function createAdminToken(): string
    {
        return $this->createTokenFor(User::factory()->admin()->create());
    }
}
