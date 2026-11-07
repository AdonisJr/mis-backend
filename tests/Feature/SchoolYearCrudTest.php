<?php

namespace Tests\Feature;

use App\Http\Controllers\SchoolYearController;
use App\Models\SchoolYear;
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
    ];

    private const SCHOOL_YEAR_KEYS = [
        'id', 'name', 'start_date', 'end_date', 'description', 'is_active', 'created_at', 'updated_at',
    ];

    private const MALFORMED_DATES = ['08/01/2026', '2026-08-01T00:00:00Z', '2026-02-30', 'not-a-date', null];

    public function test_school_years_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('school_years', self::SCHOOL_YEAR_KEYS));
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
            ->assertJsonPath('school_year.is_active', false);

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

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
