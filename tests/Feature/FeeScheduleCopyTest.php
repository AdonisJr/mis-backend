<?php

namespace Tests\Feature;

use App\Http\Controllers\FeeScheduleCopyController;
use App\Http\Requests\CopyFeeSchedulesRequest;
use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeScheduleCopyTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private const COPY_URL = self::FEE_SCHEDULES_URL.'/copy';

    private SchoolYear $targetYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->targetYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
    }

    public function test_copy_copies_every_schedule_with_lines(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $libraryItem = $this->createFeeItem(self::LIBRARY_FEE);
        $this->createSchedule($this->buildKeys(self::GRADE_7), [[$tuitionItem, '1500.50'], [$libraryItem, '200.00']]);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id), [[$tuitionItem, '2500.00']]);

        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 2, 'skipped' => []]);

        $copiedSchedules = FeeSchedule::where('school_year_id', $this->targetYear->id)->with('lines')->orderBy('id')->get();
        $this->assertCount(2, $copiedSchedules);
        $this->assertSame(['1500.50', '200.00'], $copiedSchedules[0]->lines->pluck('amount')->all());
        $this->assertSame($stemProgram->id, $copiedSchedules[1]->program_id);
        $this->assertSame(['2500.00'], $copiedSchedules[1]->lines->pluck('amount')->all());
        $this->assertDatabaseCount('fee_schedules', 4);
        $this->assertDatabaseCount('fee_schedule_lines', 6);
    }

    public function test_copy_skips_existing_keys_and_reports_them(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7));
        $this->createSchedule($this->buildKeys(self::GRADE_8));
        $existingSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7, null, $this->targetYear));
        $existingLineIds = $existingSchedule->lines()->pluck('id')->all();

        $copyResponse = $this->postCopy()->assertOk()->assertJsonPath('copied_count', 1);

        $this->assertSame([self::GRADE_7], $copyResponse->json('skipped.*.grade_level.name'));
        $this->assertSame([FeeScheduleCopyController::SKIP_EXISTS_REASON], $copyResponse->json('skipped.*.reason'));
        $this->assertSame($existingLineIds, $existingSchedule->lines()->pluck('id')->all());
        $this->assertSame(2, FeeSchedule::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_copy_drops_lines_with_inactive_items(): void
    {
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $libraryItem = $this->createFeeItem(self::LIBRARY_FEE);
        $this->createSchedule($this->buildKeys(self::GRADE_7), [[$tuitionItem, '1500.00'], [$libraryItem, '200.00']]);
        $libraryItem->forceFill(['is_active' => false])->save();

        $this->postCopy()->assertOk()->assertJsonPath('copied_count', 1);

        $copiedSchedule = FeeSchedule::where('school_year_id', $this->targetYear->id)->with('lines')->firstOrFail();
        $this->assertSame([$tuitionItem->id], $copiedSchedule->lines->pluck('fee_item_id')->all());
    }

    public function test_copy_skips_schedule_with_only_inactive_items(): void
    {
        $inactiveItem = FeeItem::factory()->inactive()->create(['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER]);
        $this->createSchedule($this->buildKeys(self::GRADE_7), [[$inactiveItem, '50.00']]);

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('copied_count', 0)
            ->assertJsonPath('skipped.0.reason', FeeScheduleCopyController::SKIP_NO_ACTIVE_ITEMS_REASON);

        $this->assertSame(0, FeeSchedule::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_copy_skips_schedule_with_inactive_program(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));
        $stemProgram->forceFill(['is_active' => false])->save();

        $this->postCopy()
            ->assertOk()
            ->assertJsonPath('copied_count', 0)
            ->assertJsonPath('skipped.0.reason', FeeScheduleCopyController::SKIP_PROGRAM_INACTIVE_REASON)
            ->assertJsonPath('skipped.0.program.code', 'STEM');

        $this->assertSame(0, FeeSchedule::where('school_year_id', $this->targetYear->id)->count());
    }

    public function test_copy_into_same_year_is_refused(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7));
        $sourceId = $this->schoolYear->id;

        foreach ([$sourceId, (string) $sourceId] as $targetId) {
            $this->withToken($this->adminToken)
                ->postJson(self::COPY_URL, ['source_school_year_id' => $sourceId, 'target_school_year_id' => $targetId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['target_school_year_id' => CopyFeeSchedulesRequest::SAME_YEAR_MESSAGE]);
        }

        $this->assertDatabaseCount('fee_schedules', 1);
    }

    public function test_copy_rejects_invalid_body(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::COPY_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_school_year_id', 'target_school_year_id']);

        foreach (['abc', '05', true, self::MISSING_ID] as $invalidId) {
            $this->withToken($this->adminToken)
                ->postJson(self::COPY_URL, ['source_school_year_id' => $invalidId, 'target_school_year_id' => $this->targetYear->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['source_school_year_id']);
        }
    }

    public function test_copy_of_empty_source_returns_zero(): void
    {
        $this->postCopy()->assertOk()->assertExactJson(['copied_count' => 0, 'skipped' => []]);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_copy_rolls_back_when_a_write_fails(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7));
        $this->createSchedule($this->buildKeys(self::GRADE_8));
        $createdCount = 0;
        FeeSchedule::created(function () use (&$createdCount) {
            $createdCount++;

            if ($createdCount === 2) {
                throw new RuntimeException('Simulated write failure');
            }
        });

        try {
            $this->postCopy()->assertStatus(500);
        } finally {
            FeeSchedule::flushEventListeners();
        }

        $this->assertSame(0, FeeSchedule::where('school_year_id', $this->targetYear->id)->count());
        $this->assertDatabaseCount('fee_schedule_lines', 2);
    }

    public function test_copy_skipped_rows_have_summary_shape_in_list_order(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        foreach ([$this->schoolYear, $this->targetYear] as $schoolYear) {
            $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id, $schoolYear));
            $this->createSchedule($this->buildKeys(self::GRADE_11, null, $schoolYear));
            $this->createSchedule($this->buildKeys(self::GRADE_7, null, $schoolYear));
        }

        $copyResponse = $this->postCopy()->assertOk()->assertJsonPath('copied_count', 0)->assertJsonCount(3, 'skipped');

        $this->assertSame(['grade_level', 'program', 'reason'], array_keys($copyResponse->json('skipped.0')));
        $this->assertSame(['id', 'name', 'stage'], array_keys($copyResponse->json('skipped.0.grade_level')));
        $this->assertSame([self::GRADE_7, self::GRADE_11, self::GRADE_11], $copyResponse->json('skipped.*.grade_level.name'));
        $this->assertSame([null, null, 'STEM'], array_map(fn (?array $program) => $program['code'] ?? null, $copyResponse->json('skipped.*.program')));
        $this->assertSame(GradeLevel::STAGE_JHS, $copyResponse->json('skipped.0.grade_level.stage'));
    }

    private function postCopy(): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->postJson(self::COPY_URL, ['source_school_year_id' => $this->schoolYear->id, 'target_school_year_id' => $this->targetYear->id]);
    }
}
