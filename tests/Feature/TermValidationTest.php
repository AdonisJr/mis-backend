<?php

namespace Tests\Feature;

use App\Http\Requests\StoreTermRequest;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class TermValidationTest extends TestCase
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

    private const MALFORMED_DATES = ['2030/06/01', '2030-06-01T00:00:00Z', '2030-02-30', 'not-a-date', null];

    public function test_create_requires_number_and_dates(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (['term_number', 'start_date', 'end_date'] as $requiredField) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), Arr::except(self::FIRST_SEMESTER, $requiredField))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);

            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::FIRST_SEMESTER, $requiredField => ''])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$requiredField]);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_non_integer_term_number(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (['abc', 1.5, [1]] as $invalidNumber) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::FIRST_SEMESTER, 'term_number' => $invalidNumber])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_number']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_number_outside_semester_range(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 3, 'start_date' => '2030-11-01', 'end_date' => '2031-03-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);

        foreach ([0, -1] as $invalidNumber) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::FIRST_SEMESTER, 'term_number' => $invalidNumber])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_number']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_number_outside_trimester_range(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach ([0, 4] as $invalidNumber) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::THIRD_TRIMESTER, 'term_number' => $invalidNumber])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_number']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_duplicate_number_in_same_year(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 1, 'start_date' => '2030-11-01', 'end_date' => '2031-03-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);

        $this->assertDatabaseCount('terms', 1);
    }

    public function test_same_number_is_allowed_in_another_year(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($semesterYear->id), self::FIRST_SEMESTER)
            ->assertCreated();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($trimesterYear->id), self::FIRST_TRIMESTER)
            ->assertCreated();

        $this->assertDatabaseCount('terms', 2);
    }

    public function test_create_rejects_malformed_dates(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (self::MALFORMED_DATES as $malformedDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::FIRST_SEMESTER, 'start_date' => $malformedDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_date']);
        }

        foreach (self::MALFORMED_DATES as $malformedDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), [...self::FIRST_SEMESTER, 'end_date' => $malformedDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_start_not_before_end(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (['2030-12-01', '2030-11-30'] as $invalidEndDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 2, 'start_date' => '2030-12-01', 'end_date' => $invalidEndDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_accepts_one_day_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);

        $this->withToken($this->createAdminToken())
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 2, 'start_date' => '2030-12-01', 'end_date' => '2030-12-02'])
            ->assertCreated();
    }

    public function test_create_rejects_start_date_outside_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (['2030-05-15', '2030-05-31'] as $earlyStartDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 1, 'start_date' => $earlyStartDate, 'end_date' => '2030-10-31'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_date'])
                ->assertJsonMissingValidationErrors(['end_date']);
        }

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 2, 'start_date' => '2031-04-01', 'end_date' => '2031-04-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_rejects_end_date_outside_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach (['2031-04-15', '2031-04-01'] as $lateEndDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 2, 'start_date' => '2030-11-01', 'end_date' => $lateEndDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date'])
                ->assertJsonMissingValidationErrors(['start_date']);
        }

        $this->assertDatabaseCount('terms', 0);
    }

    public function test_create_accepts_dates_on_school_year_boundaries(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), self::FIRST_SEMESTER)
            ->assertCreated()
            ->assertJsonPath('term.start_date', self::SEMESTER_YEAR['start_date']);

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), self::SECOND_SEMESTER)
            ->assertCreated()
            ->assertJsonPath('term.end_date', self::SEMESTER_YEAR['end_date']);
    }

    public function test_create_rejects_term_overlapping_earlier_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $adminToken = $this->createAdminToken();

        foreach (['2030-10-15', '2030-10-31'] as $overlappingStartDate) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 2, 'start_date' => $overlappingStartDate, 'end_date' => '2031-03-31'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_date' => sprintf(StoreTermRequest::OVERLAP_MESSAGE, '1st Semester')])
                ->assertJsonMissingValidationErrors(['end_date']);
        }

        $this->assertDatabaseCount('terms', 1);
    }

    public function test_create_rejects_term_overlapping_later_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 1, 'start_date' => '2030-06-01', 'end_date' => '2030-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => sprintf(StoreTermRequest::OVERLAP_MESSAGE, '2nd Semester')]);

        $this->assertDatabaseCount('terms', 1);
    }

    public function test_create_accepts_adjacent_terms_in_both_directions(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($semesterYear, self::FIRST_SEMESTER);
        $this->createTerm($trimesterYear, self::THIRD_TRIMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($semesterYear->id), self::SECOND_SEMESTER)
            ->assertCreated();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($trimesterYear->id), self::SECOND_TRIMESTER)
            ->assertCreated();
    }

    public function test_create_rejects_terms_out_of_number_order(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($semesterYear, ['term_number' => 2, 'start_date' => '2030-06-01', 'end_date' => '2030-10-31']);
        $this->createTerm($trimesterYear, ['term_number' => 1, 'start_date' => '2031-10-01', 'end_date' => '2032-01-31']);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($semesterYear->id), ['term_number' => 1, 'start_date' => '2030-11-01', 'end_date' => '2031-03-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => sprintf(StoreTermRequest::END_ORDER_MESSAGE, '2nd Semester')]);

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($trimesterYear->id), ['term_number' => 2, 'start_date' => '2031-06-01', 'end_date' => '2031-09-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => sprintf(StoreTermRequest::START_ORDER_MESSAGE, '1st Trimester')]);

        $this->assertDatabaseCount('terms', 2);
    }

    public function test_terms_may_be_added_in_any_order(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createAdminToken();

        foreach ([self::THIRD_TRIMESTER, self::FIRST_TRIMESTER, self::SECOND_TRIMESTER] as $termPayload) {
            $this->withToken($adminToken)
                ->postJson($this->buildTermsUrl($schoolYear->id), $termPayload)
                ->assertCreated();
        }

        $this->assertDatabaseCount('terms', 3);
    }

    public function test_middle_trimester_must_fit_between_neighbours(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_TRIMESTER);
        $this->createTerm($schoolYear, self::THIRD_TRIMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), [...self::SECOND_TRIMESTER, 'start_date' => '2031-09-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => sprintf(StoreTermRequest::OVERLAP_MESSAGE, '1st Trimester')]);

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), [...self::SECOND_TRIMESTER, 'end_date' => '2032-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => sprintf(StoreTermRequest::OVERLAP_MESSAGE, '3rd Trimester')]);

        $this->withToken($adminToken)
            ->postJson($this->buildTermsUrl($schoolYear->id), self::SECOND_TRIMESTER)
            ->assertCreated();
    }

    public function test_update_ignores_own_number_and_dates(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $firstTerm = $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERMS_URL.'/'.$firstTerm->id, [...self::FIRST_SEMESTER, 'start_date' => '2030-06-15', 'end_date' => '2030-10-20'])
            ->assertOk()
            ->assertJsonPath('term.start_date', '2030-06-15')
            ->assertJsonPath('term.end_date', '2030-10-20');
    }

    public function test_update_rejects_number_used_by_another_term(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $firstTerm = $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->putJson(self::TERMS_URL.'/'.$firstTerm->id, [...self::FIRST_SEMESTER, 'term_number' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);

        $this->assertSame(1, $firstTerm->fresh()->term_number);
    }

    public function test_update_can_change_to_unused_valid_number(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $term = $this->createTerm($schoolYear, self::FIRST_TRIMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$term->id, [...self::FIRST_TRIMESTER, 'term_number' => 2])
            ->assertOk()
            ->assertJsonPath('term.term_number', 2)
            ->assertJsonPath('term.label', '2nd Trimester');

        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$term->id, [...self::FIRST_TRIMESTER, 'term_number' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number']);

        $this->assertSame(2, $term->fresh()->term_number);
    }

    public function test_update_applies_date_rules(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $firstTerm = $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $secondTerm = $this->createTerm($schoolYear, self::SECOND_SEMESTER);
        $adminToken = $this->createAdminToken();

        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$firstTerm->id, [...self::FIRST_SEMESTER, 'end_date' => '2030-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => sprintf(StoreTermRequest::OVERLAP_MESSAGE, '2nd Semester')]);

        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$firstTerm->id, [...self::FIRST_SEMESTER, 'start_date' => '2030-05-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        $this->withToken($adminToken)
            ->putJson(self::TERMS_URL.'/'.$secondTerm->id, [...self::SECOND_SEMESTER, 'end_date' => '2031-04-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame('2030-06-01', $firstTerm->fresh()->start_date->toDateString());
        $this->assertSame('2030-10-31', $firstTerm->fresh()->end_date->toDateString());
        $this->assertSame('2031-03-31', $secondTerm->fresh()->end_date->toDateString());
    }

    public function test_order_rules_wait_for_valid_fields(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $this->withToken($this->createAdminToken())
            ->postJson($this->buildTermsUrl($schoolYear->id), ['term_number' => 5, 'start_date' => '2030-10-15', 'end_date' => '2031-03-31'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_number'])
            ->assertJsonMissingValidationErrors(['start_date', 'end_date']);

        $this->assertDatabaseCount('terms', 1);
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
