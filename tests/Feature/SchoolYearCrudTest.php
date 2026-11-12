<?php

namespace Tests\Feature;

use App\Http\Controllers\SchoolYearController;
use App\Http\Requests\UpdateSchoolYearRequest;
use App\Models\SchoolYear;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolYearCrudTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL_YEARS_URL = '/api/mis/school-years';

    private const TOKEN_NAME = 'test';

    private const MISSING_ID = 999;

    private const VALID_PAYLOAD = [
        'name' => '2026-2027',
        'start_date' => '2026-08-01',
        'end_date' => '2027-05-31',
        'description' => 'Regular school year',
        'term_type' => SchoolYear::TERM_TYPE_SEMESTER,
    ];

    private const SCHOOL_YEAR_KEYS = [
        'id', 'name', 'start_date', 'end_date', 'description', 'term_type', 'terms_count', 'is_active', 'created_at', 'updated_at',
    ];

    private const SCHOOL_YEAR_COLUMNS = [
        'id', 'name', 'start_date', 'end_date', 'description', 'term_type', 'is_active', 'created_at', 'updated_at',
    ];

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

    private const MALFORMED_DATES = ['08/01/2026', '2026-08-01T00:00:00Z', '2026-02-30', 'not-a-date', null];

    public function test_school_years_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('school_years', self::SCHOOL_YEAR_COLUMNS));
        $this->assertSame('date', Schema::getColumnType('school_years', 'start_date'));
        $this->assertSame('date', Schema::getColumnType('school_years', 'end_date'));

        $descriptionColumn = collect(Schema::getColumns('school_years'))->firstWhere('name', 'description');
        $this->assertTrue($descriptionColumn['nullable']);

        DB::table('school_years')->insert([
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
        ]);
        $this->assertFalse((bool) DB::table('school_years')->value('is_active'));
    }

    public function test_admin_lists_school_years_newest_first(): void
    {
        $schoolYear2024 = SchoolYear::factory()->create(['start_date' => '2024-08-01']);
        $schoolYear2026 = SchoolYear::factory()->create(['start_date' => '2026-08-01']);
        $schoolYear2025 = SchoolYear::factory()->create(['start_date' => '2025-08-01']);
        $firstSchoolYear2023 = SchoolYear::factory()->create(['start_date' => '2023-08-01']);
        $secondSchoolYear2023 = SchoolYear::factory()->create(['start_date' => '2023-08-01']);

        $listResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk();

        $this->assertSame([
            $schoolYear2026->id,
            $schoolYear2025->id,
            $schoolYear2024->id,
            $secondSchoolYear2023->id,
            $firstSchoolYear2023->id,
        ], collect($listResponse->json('school_years'))->pluck('id')->all());
    }

    public function test_list_returns_exact_shape_with_y_m_d_dates(): void
    {
        $schoolYear = SchoolYear::factory()->create([
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
        ])->fresh();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk()
            ->assertExactJson(['school_years' => [[
                'id' => $schoolYear->id,
                'name' => '2026-2027',
                'start_date' => '2026-08-01',
                'end_date' => '2027-05-31',
                'description' => null,
                'term_type' => 'semester',
                'terms_count' => 0,
                'is_active' => false,
                'created_at' => $schoolYear->created_at->toJSON(),
                'updated_at' => $schoolYear->updated_at->toJSON(),
            ]]]);
    }

    public function test_list_returns_all_rows_unpaginated(): void
    {
        SchoolYear::factory()->count(20)->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk()
            ->assertJsonCount(20, 'school_years')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('links')
            ->assertJsonMissingPath('meta');
    }

    public function test_list_returns_empty_array_when_none(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk()
            ->assertExactJson(['school_years' => []]);
    }

    public function test_admin_creates_school_year(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::SCHOOL_YEARS_URL, self::VALID_PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('school_year.name', '2026-2027')
            ->assertJsonPath('school_year.start_date', '2026-08-01')
            ->assertJsonPath('school_year.end_date', '2027-05-31')
            ->assertJsonPath('school_year.description', 'Regular school year')
            ->assertJsonPath('school_year.is_active', false)
            ->assertJsonPath('school_year.term_type', 'semester')
            ->assertJsonPath('school_year.terms_count', 0);

        $this->assertSame(self::SCHOOL_YEAR_KEYS, array_keys($createResponse->json('school_year')));

        $createdSchoolYear = SchoolYear::findOrFail($createResponse->json('school_year.id'));
        $this->assertSame('2026-08-01', $createdSchoolYear->start_date->toDateString());
        $this->assertSame('2027-05-31', $createdSchoolYear->end_date->toDateString());
        $this->assertFalse($createdSchoolYear->is_active);
    }

    public function test_create_without_description_stores_null(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::SCHOOL_YEARS_URL, Arr::except(self::VALID_PAYLOAD, 'description'))
            ->assertCreated()
            ->assertJsonPath('school_year.description', null);

        $this->assertNull(SchoolYear::findOrFail($createResponse->json('school_year.id'))->description);
    }

    public function test_create_requires_name(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $invalidPayloads = [
            Arr::except(self::VALID_PAYLOAD, 'name'),
            [...self::VALID_PAYLOAD, 'name' => ''],
            [...self::VALID_PAYLOAD, 'name' => ['x']],
        ];

        foreach ($invalidPayloads as $invalidPayload) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }

        $this->assertDatabaseCount('school_years', 0);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        SchoolYear::factory()->create(['name' => '2026-2027']);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['2026-2027', ' 2026-2027 '] as $duplicateName) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, [...self::VALID_PAYLOAD, 'name' => $duplicateName])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }

        $this->assertDatabaseCount('school_years', 1);
    }

    public function test_create_rejects_end_date_not_after_start_date(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['2026-07-31', '2026-08-01'] as $invalidEndDate) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, [...self::VALID_PAYLOAD, 'end_date' => $invalidEndDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date']);
        }

        $this->assertDatabaseCount('school_years', 0);
    }

    public function test_create_rejects_malformed_dates(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (self::MALFORMED_DATES as $malformedDate) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, [...self::VALID_PAYLOAD, 'start_date' => $malformedDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_date']);
            $this->assertDatabaseCount('school_years', 0);
        }

        foreach (self::MALFORMED_DATES as $malformedDate) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, [...self::VALID_PAYLOAD, 'end_date' => $malformedDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date']);
            $this->assertDatabaseCount('school_years', 0);
        }
    }

    public function test_create_rejects_oversized_name_and_description(): void
    {
        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::SCHOOL_YEARS_URL, [
                ...self::VALID_PAYLOAD,
                'name' => Str::repeat('a', 256),
                'description' => Str::repeat('b', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description']);

        $this->assertDatabaseCount('school_years', 0);
    }

    public function test_create_ignores_is_active(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::SCHOOL_YEARS_URL, [...self::VALID_PAYLOAD, 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('school_year.is_active', false);

        $this->assertFalse(SchoolYear::findOrFail($createResponse->json('school_year.id'))->is_active);
    }

    public function test_admin_updates_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, self::VALID_PAYLOAD)
            ->assertOk()
            ->assertJsonPath('school_year.id', $schoolYear->id)
            ->assertJsonPath('school_year.name', '2026-2027')
            ->assertJsonPath('school_year.start_date', '2026-08-01')
            ->assertJsonPath('school_year.end_date', '2027-05-31')
            ->assertJsonPath('school_year.description', 'Regular school year');

        $updatedSchoolYear = $schoolYear->fresh();
        $this->assertSame('2026-2027', $updatedSchoolYear->name);
        $this->assertSame('2026-08-01', $updatedSchoolYear->start_date->toDateString());
        $this->assertSame('2027-05-31', $updatedSchoolYear->end_date->toDateString());
    }

    public function test_update_allows_own_unchanged_name(): void
    {
        $schoolYear = SchoolYear::factory()->create(['name' => '2026-2027']);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, [...self::VALID_PAYLOAD, 'description' => 'Renamed nothing'])
            ->assertOk()
            ->assertJsonPath('school_year.name', '2026-2027')
            ->assertJsonPath('school_year.description', 'Renamed nothing');
    }

    public function test_update_requires_name(): void
    {
        $schoolYear = SchoolYear::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $invalidPayloads = [
            [...self::VALID_PAYLOAD, 'name' => ''],
            Arr::except(self::VALID_PAYLOAD, 'name'),
        ];

        foreach ($invalidPayloads as $invalidPayload) {
            $this->withToken($adminToken)
                ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name']);
        }

        $this->assertSame($schoolYear->name, $schoolYear->fresh()->name);
    }

    public function test_update_rejects_other_rows_name(): void
    {
        SchoolYear::factory()->create(['name' => '2026-2027']);
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, self::VALID_PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertSame($schoolYear->name, $schoolYear->fresh()->name);
    }

    public function test_update_rejects_end_date_not_after_start_date(): void
    {
        $schoolYear = SchoolYear::factory()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        foreach (['2026-07-31', '2026-08-01'] as $invalidEndDate) {
            $this->withToken($adminToken)
                ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, [...self::VALID_PAYLOAD, 'end_date' => $invalidEndDate])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date']);
        }

        $this->assertSame($schoolYear->end_date->toDateString(), $schoolYear->fresh()->end_date->toDateString());
    }

    public function test_update_ignores_is_active(): void
    {
        $inactiveSchoolYear = SchoolYear::factory()->create();
        $activeSchoolYear = SchoolYear::factory()->active()->create();
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$inactiveSchoolYear->id, [...self::VALID_PAYLOAD, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('school_year.is_active', false);

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$activeSchoolYear->id, [
                ...self::VALID_PAYLOAD,
                'name' => '2030-2031',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('school_year.is_active', true);

        $this->assertFalse($inactiveSchoolYear->fresh()->is_active);
        $this->assertTrue($activeSchoolYear->fresh()->is_active);
    }

    public function test_update_keeps_active_flag_of_active_row(): void
    {
        $activeSchoolYear = SchoolYear::factory()->active()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$activeSchoolYear->id, self::VALID_PAYLOAD)
            ->assertOk()
            ->assertJsonPath('school_year.is_active', true);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.self::MISSING_ID, self::VALID_PAYLOAD)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.SchoolYear::class.'] '.self::MISSING_ID]);
    }

    public function test_admin_deletes_inactive_school_year(): void
    {
        $schoolYear = SchoolYear::factory()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id)
            ->assertNoContent();

        $this->assertNull(SchoolYear::find($schoolYear->id));
    }

    public function test_delete_active_school_year_returns_422(): void
    {
        $schoolYear = SchoolYear::factory()->active()->create();

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id)
            ->assertUnprocessable()
            ->assertExactJson(['message' => SchoolYearController::ACTIVE_DELETE_MESSAGE]);

        $this->assertNotNull($schoolYear->fresh());
        $this->assertTrue($schoolYear->fresh()->is_active);
    }

    public function test_delete_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::SCHOOL_YEARS_URL.'/'.self::MISSING_ID)
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.SchoolYear::class.'] '.self::MISSING_ID]);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)->putJson(self::SCHOOL_YEARS_URL.'/abc', self::VALID_PAYLOAD)->assertNotFound();
        $this->withToken($adminToken)->deleteJson(self::SCHOOL_YEARS_URL.'/abc')->assertNotFound();
        $this->withToken($adminToken)->putJson(self::SCHOOL_YEARS_URL.'/set-active/abc')->assertNotFound();
    }

    public function test_name_unique_index_rejects_duplicate_at_db_level(): void
    {
        SchoolYear::create(self::VALID_PAYLOAD);

        $this->expectException(UniqueConstraintViolationException::class);

        SchoolYear::create(self::VALID_PAYLOAD);
    }

    public function test_term_type_defaults_to_semester_in_the_database(): void
    {
        DB::table('school_years')->insert([
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
        ]);

        $this->assertSame('semester', DB::table('school_years')->value('term_type'));
    }

    public function test_migration_backfills_existing_rows_with_semester(): void
    {
        $termTypeMigration = require glob(database_path('migrations/*_add_term_type_to_school_years_table.php'))[0];
        $termTypeMigration->down();
        $this->assertFalse(Schema::hasColumn('school_years', 'term_type'));

        DB::table('school_years')->insert(['name' => '2029-2030', 'start_date' => '2029-06-01', 'end_date' => '2030-03-31']);
        $termTypeMigration->up();

        $this->assertSame('semester', DB::table('school_years')->where('name', '2029-2030')->value('term_type'));
    }

    public function test_create_requires_valid_term_type(): void
    {
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $invalidPayloads = [
            Arr::except(self::VALID_PAYLOAD, 'term_type'),
            [...self::VALID_PAYLOAD, 'term_type' => ''],
            [...self::VALID_PAYLOAD, 'term_type' => 'quarter'],
            [...self::VALID_PAYLOAD, 'term_type' => 'Semester'],
            [...self::VALID_PAYLOAD, 'term_type' => ['semester']],
        ];

        foreach ($invalidPayloads as $invalidPayload) {
            $this->withToken($adminToken)
                ->postJson(self::SCHOOL_YEARS_URL, $invalidPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['term_type']);
        }

        $this->assertDatabaseCount('school_years', 0);
    }

    public function test_admin_creates_trimester_school_year(): void
    {
        $createResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->postJson(self::SCHOOL_YEARS_URL, self::TRIMESTER_YEAR)
            ->assertCreated()
            ->assertJsonPath('school_year.term_type', 'trimester')
            ->assertJsonPath('school_year.terms_count', 0);

        $this->assertSame('trimester', SchoolYear::findOrFail($createResponse->json('school_year.id'))->term_type);
    }

    public function test_list_includes_term_type_and_terms_count(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($semesterYear, self::FIRST_SEMESTER);
        $this->createTerm($semesterYear, self::SECOND_SEMESTER);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);

        $listResponse = $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->getJson(self::SCHOOL_YEARS_URL)
            ->assertOk();

        $listedSchoolYears = collect($listResponse->json('school_years'))->keyBy('id');
        $this->assertSame(2, $listedSchoolYears[$semesterYear->id]['terms_count']);
        $this->assertSame('semester', $listedSchoolYears[$semesterYear->id]['term_type']);
        $this->assertSame(0, $listedSchoolYears[$trimesterYear->id]['terms_count']);
        $this->assertSame('trimester', $listedSchoolYears[$trimesterYear->id]['term_type']);
    }

    public function test_update_allows_term_type_change_without_terms(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$semesterYear->id, [...self::SEMESTER_YEAR, 'term_type' => 'trimester'])
            ->assertOk()
            ->assertJsonPath('school_year.term_type', 'trimester');

        $this->withToken($adminToken)
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$trimesterYear->id, [...self::TRIMESTER_YEAR, 'term_type' => 'semester'])
            ->assertOk()
            ->assertJsonPath('school_year.term_type', 'semester');

        $this->assertSame('trimester', $semesterYear->fresh()->term_type);
        $this->assertSame('semester', $trimesterYear->fresh()->term_type);
    }

    public function test_update_rejects_term_type_change_when_year_has_terms(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, [...self::SEMESTER_YEAR, 'term_type' => 'trimester'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['term_type' => UpdateSchoolYearRequest::TERM_TYPE_LOCKED_MESSAGE]);

        $this->assertSame('semester', $schoolYear->fresh()->term_type);
    }

    public function test_update_keeps_same_term_type_when_year_has_terms(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->putJson(self::SCHOOL_YEARS_URL.'/'.$schoolYear->id, [...self::SEMESTER_YEAR, 'description' => 'With terms'])
            ->assertOk()
            ->assertJsonPath('school_year.description', 'With terms')
            ->assertJsonPath('school_year.terms_count', 2)
            ->assertJsonPath('school_year.term_type', 'semester');
    }

    public function test_update_rejects_dates_that_leave_terms_outside(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $updateUrl = self::SCHOOL_YEARS_URL.'/'.$schoolYear->id;

        $this->withToken($adminToken)
            ->putJson($updateUrl, [...self::SEMESTER_YEAR, 'start_date' => '2030-06-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date' => UpdateSchoolYearRequest::START_AFTER_TERMS_MESSAGE])
            ->assertJsonMissingValidationErrors(['end_date']);

        $this->withToken($adminToken)
            ->putJson($updateUrl, [...self::SEMESTER_YEAR, 'end_date' => '2031-03-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => UpdateSchoolYearRequest::END_BEFORE_TERMS_MESSAGE]);

        $this->withToken($adminToken)
            ->putJson($updateUrl, [...self::SEMESTER_YEAR, 'end_date' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        $this->assertSame('2030-06-01', $schoolYear->fresh()->start_date->toDateString());
        $this->assertSame('2031-03-31', $schoolYear->fresh()->end_date->toDateString());
    }

    public function test_update_allows_dates_that_keep_terms_inside(): void
    {
        $schoolYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($schoolYear, self::FIRST_SEMESTER);
        $this->createTerm($schoolYear, self::SECOND_SEMESTER);
        $adminToken = $this->createTokenFor(User::factory()->admin()->create());
        $updateUrl = self::SCHOOL_YEARS_URL.'/'.$schoolYear->id;

        $this->withToken($adminToken)
            ->putJson($updateUrl, [...self::SEMESTER_YEAR, 'description' => 'Same edges'])
            ->assertOk()
            ->assertJsonPath('school_year.description', 'Same edges');

        $this->withToken($adminToken)
            ->putJson($updateUrl, [...self::SEMESTER_YEAR, 'start_date' => '2030-05-01', 'end_date' => '2031-04-30'])
            ->assertOk()
            ->assertJsonPath('school_year.start_date', '2030-05-01')
            ->assertJsonPath('school_year.end_date', '2031-04-30');

        $this->assertSame('2030-05-01', $schoolYear->fresh()->start_date->toDateString());
        $this->assertSame('2031-04-30', $schoolYear->fresh()->end_date->toDateString());
    }

    public function test_deleting_school_year_deletes_its_terms(): void
    {
        $semesterYear = SchoolYear::factory()->create(self::SEMESTER_YEAR);
        $this->createTerm($semesterYear, self::FIRST_SEMESTER);
        $this->createTerm($semesterYear, self::SECOND_SEMESTER);
        $trimesterYear = SchoolYear::factory()->create(self::TRIMESTER_YEAR);
        $trimesterTerm = $this->createTerm($trimesterYear, ['term_number' => 1, 'start_date' => '2031-06-01', 'end_date' => '2031-09-30']);

        $this->withToken($this->createTokenFor(User::factory()->admin()->create()))
            ->deleteJson(self::SCHOOL_YEARS_URL.'/'.$semesterYear->id)
            ->assertNoContent();

        $this->assertSame(0, Term::where('school_year_id', $semesterYear->id)->count());
        $this->assertNotNull($trimesterTerm->fresh());
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
