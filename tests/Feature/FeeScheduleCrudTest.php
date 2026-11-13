<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateFeeScheduleRequest;
use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\FeeScheduleLine;
use App\Models\GradeLevel;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeScheduleCrudTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['id', 'school_year_id', 'grade_level_id', 'program_id', 'created_at', 'updated_at', 'grade_level', 'program', 'lines_count', 'total', 'subtotals'];

    private const DETAIL_KEYS = [...self::SUMMARY_KEYS, 'lines'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_fee_schedule_tables_have_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('fee_schedules', ['id', 'school_year_id', 'grade_level_id', 'program_id', 'created_at', 'updated_at']));
        $this->assertTrue(Schema::hasColumns('fee_schedule_lines', ['id', 'fee_schedule_id', 'fee_item_id', 'amount', 'created_at', 'updated_at']));

        $scheduleColumns = collect(Schema::getColumns('fee_schedules'))->keyBy('name');
        $this->assertFalse($scheduleColumns['school_year_id']['nullable']);
        $this->assertFalse($scheduleColumns['grade_level_id']['nullable']);
        $this->assertTrue($scheduleColumns['program_id']['nullable']);
        $this->assertFalse(collect(Schema::getColumns('fee_schedule_lines'))->keyBy('name')['amount']['nullable']);
    }

    public function test_foreign_keys_restrict_and_cascade(): void
    {
        $scheduleForeignKeys = collect(Schema::getForeignKeys('fee_schedules'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();
        $lineForeignKeys = collect(Schema::getForeignKeys('fee_schedule_lines'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();

        $this->assertEquals([
            'school_year_id' => ['school_years', 'restrict'],
            'grade_level_id' => ['grade_levels', 'restrict'],
            'program_id' => ['programs', 'restrict'],
        ], $scheduleForeignKeys);
        $this->assertEquals([
            'fee_schedule_id' => ['fee_schedules', 'cascade'],
            'fee_item_id' => ['fee_items', 'restrict'],
        ], $lineForeignKeys);

        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7));
        DB::table('fee_schedules')->where('id', $feeSchedule->id)->delete();
        $this->assertDatabaseCount('fee_schedule_lines', 0);
    }

    public function test_admin_creates_jhs_schedule(): void
    {
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $libraryItem = $this->createFeeItem(self::LIBRARY_FEE);

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_7), 'lines' => $this->buildLines([[$tuitionItem, '15000'], [$libraryItem, '250.50']])])
            ->assertCreated()
            ->assertJsonPath('fee_schedule.school_year_id', $this->schoolYear->id)
            ->assertJsonPath('fee_schedule.program_id', null)
            ->assertJsonPath('fee_schedule.program', null)
            ->assertJsonPath('fee_schedule.grade_level', ['id' => $this->findGradeLevelId(self::GRADE_7), 'name' => self::GRADE_7, 'stage' => GradeLevel::STAGE_JHS])
            ->assertJsonPath('fee_schedule.lines_count', 2)
            ->assertJsonPath('fee_schedule.total', '15250.50')
            ->assertJsonPath('fee_schedule.subtotals', ['tuition' => '15000.00', 'miscellaneous' => '250.50', 'other' => '0.00'])
            ->assertJsonPath('fee_schedule.lines.0.fee_item', ['id' => $tuitionItem->id, 'name' => self::TUITION_FEE, 'category' => FeeItem::CATEGORY_TUITION, 'is_active' => true])
            ->assertJsonPath('fee_schedule.lines.0.amount', '15000.00');

        $this->assertSame(self::DETAIL_KEYS, array_keys($createResponse->json('fee_schedule')));
        $this->assertSame(['id', 'fee_item_id', 'amount', 'fee_item'], array_keys($createResponse->json('fee_schedule.lines.0')));
        $this->assertDatabaseCount('fee_schedule_lines', 2);
    }

    public function test_admin_creates_shs_default_and_program_schedules(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $lines = $this->buildLines([[$feeItem, '20000.00']]);

        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_11), 'lines' => $lines])
            ->assertCreated()
            ->assertJsonPath('fee_schedule.program_id', null);
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_11, $stemProgram->id), 'lines' => $lines])
            ->assertCreated()
            ->assertJsonPath('fee_schedule.program', ['id' => $stemProgram->id, 'code' => 'STEM', 'name' => self::STEM_PROGRAM['name']]);

        $this->assertDatabaseCount('fee_schedules', 2);
    }

    public function test_create_accepts_numeric_strings_and_returns_two_decimal_amounts(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $keys = $this->buildKeys(self::GRADE_7);

        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [
                'school_year_id' => (string) $keys['school_year_id'],
                'grade_level_id' => (string) $keys['grade_level_id'],
                'program_id' => null,
                'lines' => [['fee_item_id' => (string) $feeItem->id, 'amount' => '1500.5']],
            ])
            ->assertCreated()
            ->assertJsonPath('fee_schedule.school_year_id', $keys['school_year_id'])
            ->assertJsonPath('fee_schedule.grade_level_id', $keys['grade_level_id'])
            ->assertJsonPath('fee_schedule.lines.0.fee_item_id', $feeItem->id)
            ->assertJsonPath('fee_schedule.lines.0.amount', '1500.50');
    }

    public function test_total_and_subtotals_sum_exactly(): void
    {
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $otherItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);
        $emptySchedule = FeeSchedule::factory()->create($this->buildKeys(self::GRADE_8));
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$tuitionItem, '0.10'], [$otherItem, '0.20']]);

        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id)
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '0.30')
            ->assertJsonPath('fee_schedule.subtotals', ['tuition' => '0.10', 'miscellaneous' => '0.00', 'other' => '0.20']);
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'/'.$emptySchedule->id)
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '0.00')
            ->assertJsonPath('fee_schedule.subtotals', ['tuition' => '0.00', 'miscellaneous' => '0.00', 'other' => '0.00']);
    }

    public function test_create_ignores_unvalidated_line_keys(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $otherSchedule = $this->createSchedule($this->buildKeys(self::GRADE_8));

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [
                ...$this->buildKeys(self::GRADE_7),
                'lines' => [['id' => 777, 'fee_schedule_id' => $otherSchedule->id, 'fee_item_id' => $feeItem->id, 'amount' => '100.00', 'note' => 'x']],
            ])
            ->assertCreated();

        $createdLine = FeeScheduleLine::where('fee_item_id', $feeItem->id)->firstOrFail();
        $this->assertNotSame(777, $createdLine->id);
        $this->assertSame($createResponse->json('fee_schedule.id'), $createdLine->fee_schedule_id);
        $this->assertDatabaseCount('fee_schedule_lines', 2);
    }

    public function test_show_returns_schedule_with_lines_and_totals(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$feeItem, '1000.00']]);

        $showResponse = $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id)
            ->assertOk()
            ->assertJsonPath('fee_schedule.id', $feeSchedule->id)
            ->assertJsonPath('fee_schedule.lines_count', 1)
            ->assertJsonPath('fee_schedule.lines.0.fee_item_id', $feeItem->id);

        $this->assertSame(self::DETAIL_KEYS, array_keys($showResponse->json('fee_schedule')));
    }

    public function test_show_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::FEE_SCHEDULES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_admin_updates_lines_as_a_set(): void
    {
        $tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $libraryItem = $this->createFeeItem(self::LIBRARY_FEE);
        $laboratoryItem = $this->createFeeItem(self::LABORATORY_FEE);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$tuitionItem, '1000.00'], [$libraryItem, '200.00']]);
        $oldLineIds = $feeSchedule->lines()->pluck('id')->all();

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, ['lines' => $this->buildLines([[$tuitionItem, '1100.00'], [$laboratoryItem, '300.25']])])
            ->assertOk()
            ->assertJsonPath('fee_schedule.lines_count', 2)
            ->assertJsonPath('fee_schedule.total', '1400.25')
            ->assertJsonPath('fee_schedule.lines.1.fee_item_id', $laboratoryItem->id);

        $this->assertSame([], array_intersect($oldLineIds, $feeSchedule->lines()->pluck('id')->all()));
        $this->assertDatabaseCount('fee_schedule_lines', 2);
        $this->assertDatabaseMissing('fee_schedule_lines', ['fee_item_id' => $libraryItem->id]);
    }

    public function test_update_rejects_key_changes(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$feeItem, '1000.00']]);
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);
        $changedKeys = [
            'school_year_id' => $otherYear->id,
            'grade_level_id' => $this->findGradeLevelId(self::GRADE_8),
            'program_id' => $stemProgram->id,
        ];

        foreach ($changedKeys as $keyField => $changedValue) {
            $this->withToken($this->adminToken)
                ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, [$keyField => $changedValue, 'lines' => $this->buildLines([[$feeItem, '9.00']])])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$keyField => UpdateFeeScheduleRequest::KEY_LOCKED_MESSAGE]);
        }

        $this->assertSame($this->buildKeys(self::GRADE_7), $feeSchedule->fresh()->only(FeeSchedule::KEY_FIELDS));
        $this->assertDatabaseHas('fee_schedule_lines', ['fee_schedule_id' => $feeSchedule->id, 'amount' => '1000.00']);
    }

    public function test_update_accepts_unchanged_or_absent_keys(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$feeItem, '1000.00']]);
        $keys = $this->buildKeys(self::GRADE_7);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, [...$keys, 'lines' => $this->buildLines([[$feeItem, '1200.00']])])
            ->assertOk();
        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, [
                'school_year_id' => (string) $keys['school_year_id'],
                'program_id' => '',
                'lines' => $this->buildLines([[$feeItem, '1300.00']]),
            ])
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '1300.00');
        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, ['lines' => $this->buildLines([[$feeItem, '1400.00']])])
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '1400.00');
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.self::MISSING_ID, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.self::MISSING_ID, ['lines' => $this->buildLines([[$feeItem, '100.00']])])
            ->assertNotFound();
    }

    public function test_admin_deletes_schedule_and_its_lines(): void
    {
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7));
        $keptSchedule = $this->createSchedule($this->buildKeys(self::GRADE_8));

        $this->withToken($this->adminToken)->deleteJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id)->assertNoContent();

        $this->assertDatabaseMissing('fee_schedules', ['id' => $feeSchedule->id]);
        $this->assertDatabaseCount('fee_schedule_lines', 1);
        $this->assertDatabaseHas('fee_schedules', ['id' => $keptSchedule->id]);
    }

    public function test_delete_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::FEE_SCHEDULES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_list_requires_school_year(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['school_year_id']);
    }

    public function test_list_orders_by_grade_level_default_first_then_program_code(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $abmProgram = $this->createProgram(self::ABM_PROGRAM);
        $stemSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));
        $abmSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11, $abmProgram->id));
        $defaultSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11));
        $juniorSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7));

        $listedIds = $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->json('fee_schedules.*.id');

        $this->assertSame([$juniorSchedule->id, $defaultSchedule->id, $abmSchedule->id, $stemSchedule->id], $listedIds);
    }

    public function test_list_filters_by_grade_level(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7));
        $gradeEightSchedule = $this->createSchedule($this->buildKeys(self::GRADE_8));
        $this->createSchedule($this->buildKeys(self::GRADE_7, null, SchoolYear::factory()->create(self::SCHOOL_YEAR_2031)));

        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?'.http_build_query(['school_year_id' => $this->schoolYear->id, 'grade_level_id' => $this->findGradeLevelId(self::GRADE_8)]))
            ->assertOk()
            ->assertJsonCount(1, 'fee_schedules')
            ->assertJsonPath('fee_schedules.0.id', $gradeEightSchedule->id);
    }

    public function test_list_filters_by_program_and_default_only(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $stemSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));
        $defaultSchedule = $this->createSchedule($this->buildKeys(self::GRADE_11));
        $baseQuery = ['school_year_id' => $this->schoolYear->id];

        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?'.http_build_query([...$baseQuery, 'program_id' => $stemProgram->id]))
            ->assertOk()
            ->assertJsonCount(1, 'fee_schedules')
            ->assertJsonPath('fee_schedules.0.id', $stemSchedule->id);
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?'.http_build_query([...$baseQuery, 'program_id' => 'none']))
            ->assertOk()
            ->assertJsonCount(1, 'fee_schedules')
            ->assertJsonPath('fee_schedules.0.id', $defaultSchedule->id);
    }

    public function test_list_rows_carry_totals_without_lines(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7), [[$this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION), '1500.50']]);

        $listResponse = $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->assertJsonPath('fee_schedules.0.total', '1500.50')
            ->assertJsonPath('fee_schedules.0.lines_count', 1);

        $this->assertSame(self::SUMMARY_KEYS, array_keys($listResponse->json('fee_schedules.0')));
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        foreach (range(1, 16) as $unusedIndex) {
            $this->createSchedule($this->buildKeys(self::GRADE_7));
        }

        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id)
            ->assertOk()
            ->assertJsonCount(15, 'fee_schedules')
            ->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => 15, 'total' => 16]);
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id.'&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'fee_schedules');
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));

        DB::enableQueryLog();
        $this->fetchScheduleList()->assertOk()->assertJsonCount(1, 'fee_schedules');
        $singleRowCount = $this->countNonTokenQueries();

        $this->createSchedule($this->buildKeys(self::GRADE_8));
        $this->createSchedule($this->buildKeys(self::GRADE_11, $stemProgram->id));
        $this->createSchedule($this->buildKeys(self::GRADE_11));
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchScheduleList()->assertOk()->assertJsonCount(4, 'fee_schedules');

        $this->assertSame($singleRowCount, $this->countNonTokenQueries());
    }

    private function fetchScheduleList(): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::FEE_SCHEDULES_URL.'?school_year_id='.$this->schoolYear->id);
    }

    private function countNonTokenQueries(): int
    {
        // Sanctum writes the token's last_used_at only on the first request, so token queries are left out of the comparison
        return collect(DB::getQueryLog())->reject(fn (array $loggedQuery) => str_contains($loggedQuery['query'], 'personal_access_tokens'))->count();
    }
}
