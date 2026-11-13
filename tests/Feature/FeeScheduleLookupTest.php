<?php

namespace Tests\Feature;

use App\Models\FeeSchedule;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeScheduleLookupTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private const LOOKUP_URL = self::FEE_SCHEDULES_URL.'/lookup';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_lookup_returns_program_schedule_when_it_exists(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->createSchedule($this->buildKeys(self::GRADE_11));
        $programSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));

        $this->fetchLookup($this->buildKeys(self::GRADE_11, $stemProgram->id))
            ->assertOk()
            ->assertJsonPath('fee_schedule.id', $programSchedule->id)
            ->assertJsonPath('fee_schedule.program_id', $stemProgram->id)
            ->assertJsonCount(1, 'fee_schedule.lines');
    }

    public function test_lookup_falls_back_to_default_schedule_for_a_program(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $defaultSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11));

        $this->fetchLookup($this->buildKeys(self::GRADE_11, $stemProgram->id))
            ->assertOk()
            ->assertJsonPath('fee_schedule.id', $defaultSchedule->id)
            ->assertJsonPath('fee_schedule.program_id', null);
    }

    public function test_lookup_returns_default_schedule_without_program(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));
        $defaultSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11));

        $this->fetchLookup($this->buildKeys(self::GRADE_11))
            ->assertOk()
            ->assertJsonPath('fee_schedule.id', $defaultSchedule->id);
    }

    public function test_lookup_returns_null_when_nothing_matches(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));

        $this->fetchLookup($this->buildKeys(self::GRADE_11))->assertOk()->assertExactJson(['fee_schedule' => null]);
        $this->fetchLookup($this->buildKeys(self::GRADE_8))->assertOk()->assertExactJson(['fee_schedule' => null]);
    }

    public function test_lookup_ignores_other_school_years(): void
    {
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $this->createSchedule($this->buildKeys(self::GRADE_7, null, $otherYear));

        $this->fetchLookup($this->buildKeys(self::GRADE_7))->assertOk()->assertExactJson(['fee_schedule' => null]);
    }

    public function test_lookup_rejects_invalid_params(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::LOOKUP_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id', 'grade_level_id']);

        foreach (['abc', '05', self::MISSING_ID] as $invalidId) {
            $this->fetchLookup([...$this->buildKeys(self::GRADE_7), 'program_id' => $invalidId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['program_id']);
        }
    }

    public function test_find_effective_resolves_in_process(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $defaultSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11));
        $programSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));

        $this->assertSame($programSchedule->id, (new FeeSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id)))->findEffective()->id);
        $this->assertSame($defaultSchedule->id, (new FeeSchedule($this->buildKeys(self::GRADE_11)))->findEffective()->id);
        $this->assertNull((new FeeSchedule($this->buildKeys(self::GRADE_12)))->findEffective());
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private function fetchLookup(array $queryParams): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::LOOKUP_URL.'?'.http_build_query($queryParams));
    }
}
