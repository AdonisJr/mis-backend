<?php

namespace Tests\Feature;

use App\Http\Requests\StoreFeeScheduleRequest;
use App\Http\Requests\StoreSectionRequest;
use App\Models\FeeItem;
use App\Models\Program;
use App\Models\SchoolYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeScheduleRulesTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private FeeItem $tuitionItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
        $this->tuitionItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideDuplicateKeys(): array
    {
        return [
            'jhs default' => [self::GRADE_7, null],
            'shs default' => [self::GRADE_11, null],
            'shs program' => [self::GRADE_11, 'STEM'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidAmounts(): array
    {
        return [
            'zero' => [0],
            'zero string' => ['0.00'],
            'negative' => [-1],
            'three decimals' => [1.234],
            'exponent' => ['1e3'],
            'text' => ['abc'],
            'boolean' => [true],
            'overflow' => [10000000000],
        ];
    }

    public function test_college_grade_level_is_refused(): void
    {
        $bsitProgram = $this->createProgram(self::BSIT_PROGRAM);

        $this->postSchedule($this->buildKeys(self::FIRST_YEAR_COLLEGE, $bsitProgram->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id' => StoreFeeScheduleRequest::GRADE_LEVEL_NOT_BILLABLE_MESSAGE])
            ->assertJsonMissingValidationErrors(['program_id']);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_jhs_schedule_with_program_is_refused(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);

        $this->postSchedule($this->buildKeys(self::GRADE_7, $stemProgram->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreFeeScheduleRequest::PROGRAM_NOT_ALLOWED_MESSAGE]);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_shs_schedule_with_college_program_is_refused(): void
    {
        $bsitProgram = $this->createProgram(self::BSIT_PROGRAM);

        $this->postSchedule($this->buildKeys(self::GRADE_11, $bsitProgram->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_STAGE_MESSAGE]);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_inactive_program_is_refused_on_create(): void
    {
        $inactiveProgram = Program::factory()->shs()->inactive()->create(self::STEM_PROGRAM);

        $this->postSchedule($this->buildKeys(self::GRADE_11, $inactiveProgram->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['program_id' => StoreSectionRequest::PROGRAM_INACTIVE_MESSAGE]);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    #[DataProvider('provideDuplicateKeys')]
    public function test_duplicate_key_is_refused(string $gradeLevelName, ?string $programCode): void
    {
        $programId = $programCode ? $this->createProgram(self::STEM_PROGRAM)->id : null;
        $this->createSchedule($this->buildKeys($gradeLevelName, $programId));

        $this->postSchedule($this->buildKeys($gradeLevelName, $programId))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['grade_level_id' => StoreFeeScheduleRequest::KEY_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('fee_schedules', 1);
    }

    public function test_same_key_in_another_school_year_is_allowed(): void
    {
        $this->createSchedule($this->buildKeys(self::GRADE_7));
        $otherYear = SchoolYear::factory()->create(self::SCHOOL_YEAR_2031);

        $this->postSchedule($this->buildKeys(self::GRADE_7, null, $otherYear))->assertCreated();

        $this->assertDatabaseCount('fee_schedules', 2);
    }

    public function test_lines_are_required(): void
    {
        $keys = $this->buildKeys(self::GRADE_7);

        foreach ([$keys, [...$keys, 'lines' => []], [...$keys, 'lines' => ['first' => ['fee_item_id' => $this->tuitionItem->id, 'amount' => '1.00']]]] as $invalidBody) {
            $this->withToken($this->adminToken)
                ->postJson(self::FEE_SCHEDULES_URL, $invalidBody)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['lines']);
        }

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_line_fee_item_must_exist_and_be_active(): void
    {
        $inactiveItem = FeeItem::factory()->inactive()->create(['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER]);
        $keys = $this->buildKeys(self::GRADE_7);

        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$keys, 'lines' => [['fee_item_id' => self::MISSING_ID, 'amount' => '1.00']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.fee_item_id']);
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$keys, 'lines' => $this->buildLines([[$this->tuitionItem, '1.00'], [$inactiveItem, '2.00']])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.fee_item_id' => StoreFeeScheduleRequest::FEE_ITEM_INACTIVE_MESSAGE])
            ->assertJsonMissingValidationErrors(['lines.0.fee_item_id']);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_fee_item_appears_once_per_schedule(): void
    {
        $lines = [
            ['fee_item_id' => $this->tuitionItem->id, 'amount' => '1.00'],
            ['fee_item_id' => (string) $this->tuitionItem->id, 'amount' => '2.00'],
        ];

        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_7), 'lines' => $lines])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.fee_item_id']);

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    #[DataProvider('provideInvalidAmounts')]
    public function test_invalid_amount_is_refused(mixed $invalidAmount): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_7), 'lines' => [['fee_item_id' => $this->tuitionItem->id, 'amount' => $invalidAmount]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.amount']);

        $this->assertDatabaseCount('fee_schedules', 0);
        $this->assertDatabaseCount('fee_schedule_lines', 0);
    }

    public function test_amount_edges_pass(): void
    {
        $items = [$this->tuitionItem, $this->createFeeItem(self::LIBRARY_FEE), $this->createFeeItem(self::LABORATORY_FEE), $this->createFeeItem(self::ID_CARD)];
        $submittedAmounts = ['0.01', 9999999999.99, '100', 100.5];
        $lines = array_map(fn (FeeItem $feeItem, mixed $amount) => ['fee_item_id' => $feeItem->id, 'amount' => $amount], $items, $submittedAmounts);

        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$this->buildKeys(self::GRADE_7), 'lines' => $lines])
            ->assertCreated();

        $this->assertSame(['0.01', '9999999999.99', '100.00', '100.50'], $createResponse->json('fee_schedule.lines.*.amount'));
    }

    public function test_malformed_ids_are_refused(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $validKeys = $this->buildKeys(self::GRADE_11, $stemProgram->id);

        foreach (array_keys($validKeys) as $keyField) {
            foreach (['05', true, 'abc', self::MISSING_ID] as $malformedId) {
                $this->postSchedule([...$validKeys, $keyField => $malformedId])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$keyField]);
            }
        }

        $this->assertDatabaseCount('fee_schedules', 0);
    }

    public function test_update_keeps_unchanged_line_with_inactive_fee_item(): void
    {
        $libraryItem = $this->createFeeItem(self::LIBRARY_FEE);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$this->tuitionItem, '1000.00'], [$libraryItem, '200.00']]);
        $this->tuitionItem->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id, ['lines' => [
                ['fee_item_id' => (string) $this->tuitionItem->id, 'amount' => '1000'],
                ['fee_item_id' => $libraryItem->id, 'amount' => '250.00'],
            ]])
            ->assertOk()
            ->assertJsonPath('fee_schedule.total', '1250.00')
            ->assertJsonPath('fee_schedule.lines.0.fee_item.is_active', false);
    }

    public function test_update_refuses_inactive_fee_item_when_amount_changes_or_newly_added(): void
    {
        $inactiveItem = FeeItem::factory()->inactive()->create(['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER]);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$this->tuitionItem, '1000.00']]);
        $this->tuitionItem->forceFill(['is_active' => false])->save();
        $scheduleUrl = self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id;

        $this->withToken($this->adminToken)
            ->putJson($scheduleUrl, ['lines' => $this->buildLines([[$this->tuitionItem, '1000.01']])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.fee_item_id' => StoreFeeScheduleRequest::FEE_ITEM_INACTIVE_MESSAGE]);
        $this->withToken($this->adminToken)
            ->putJson($scheduleUrl, ['lines' => $this->buildLines([[$this->tuitionItem, '1000.00'], [$inactiveItem, '5.00']])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.fee_item_id' => StoreFeeScheduleRequest::FEE_ITEM_INACTIVE_MESSAGE])
            ->assertJsonMissingValidationErrors(['lines.0.fee_item_id']);

        $this->assertDatabaseCount('fee_schedule_lines', 1);
        $this->assertDatabaseHas('fee_schedule_lines', ['fee_schedule_id' => $feeSchedule->id, 'amount' => '1000.00']);
    }

    public function test_rules_wait_for_valid_ids(): void
    {
        $stemProgram = $this->createProgram(self::STEM_PROGRAM);
        $keys = $this->buildKeys(self::GRADE_7, $stemProgram->id);

        $errorResponse = $this->postSchedule([...$keys, 'grade_level_id' => self::MISSING_ID])->assertUnprocessable();

        $this->assertSame(['grade_level_id'], array_keys($errorResponse->json('errors')));
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    private function postSchedule(array $keys): TestResponse
    {
        return $this->withToken($this->adminToken)
            ->postJson(self::FEE_SCHEDULES_URL, [...$keys, 'lines' => $this->buildLines([[$this->tuitionItem, '1500.00']])]);
    }
}
