<?php

namespace Tests\Feature;

use App\Models\SchoolYear;
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

    private function createTokenFor(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME)->plainTextToken;
    }
}
