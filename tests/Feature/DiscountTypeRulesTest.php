<?php

namespace Tests\Feature;

use App\Http\Requests\StoreDiscountTypeRequest;
use App\Http\Requests\UpdateDiscountTypeRequest;
use App\Models\DiscountType;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithDiscounts;
use Tests\TestCase;

class DiscountTypeRulesTest extends TestCase
{
    use InteractsWithDiscounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidFixedValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'three decimals' => [1.234],
            'exponent string' => ['1e3'],
            'boolean' => [true],
            'above the maximum' => [10000000000],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidPercentValues(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'above one hundred' => [100.01],
            'three decimals' => [12.345],
            'text' => ['abc'],
        ];
    }

    public function test_create_requires_name_source_value_type_value_base_and_stages(): void
    {
        $this->postType([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'source', 'value_type', 'value', 'base', 'stages']);

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_name_is_unique_ignoring_case(): void
    {
        $this->createType(['name' => self::SIBLING_TYPE]);

        $this->postType($this->buildTypePayload(['name' => 'SIBLING DISCOUNT']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['name' => [StoreDiscountTypeRequest::NAME_TAKEN_MESSAGE]]);

        $this->assertDatabaseCount('discount_types', 1);
    }

    public function test_update_refuses_name_taken_by_another_type_ignoring_case(): void
    {
        $this->createType(['name' => self::SIBLING_TYPE]);
        $otherType = $this->createType(['name' => self::SCHOLARSHIP_TYPE]);

        $this->putType($otherType->id, $this->buildTypePayload(['name' => 'sibling DISCOUNT']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['name' => [StoreDiscountTypeRequest::NAME_TAKEN_MESSAGE]]);

        $this->assertSame(self::SCHOLARSHIP_TYPE, $otherType->fresh()->name);
    }

    public function test_oversized_name_and_description_are_refused(): void
    {
        $this->postType($this->buildTypePayload(['name' => str_repeat('n', 101), 'description' => str_repeat('d', 1001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description']);
        $this->assertDatabaseCount('discount_types', 0);

        $this->postType($this->buildTypePayload(['name' => str_repeat('n', 100), 'description' => str_repeat('d', 1000)]))->assertCreated();
    }

    public function test_description_is_optional(): void
    {
        $this->postType($this->buildTypePayload(['name' => self::SIBLING_TYPE, 'description' => null]))->assertCreated()->assertJsonPath('discount_type.description', null);

        $absentPayload = $this->buildTypePayload(['name' => self::SCHOLARSHIP_TYPE]);
        unset($absentPayload['description']);
        $this->postType($absentPayload)->assertCreated()->assertJsonPath('discount_type.description', null);
    }

    public function test_source_must_be_known(): void
    {
        foreach (['Government', 'private', null] as $invalidSource) {
            $this->postType($this->buildTypePayload(['source' => $invalidSource]))->assertUnprocessable()->assertJsonValidationErrorFor('source');
        }

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_value_type_must_be_known(): void
    {
        foreach (['Percent', 'amount', null] as $invalidValueType) {
            $this->postType($this->buildTypePayload(['value_type' => $invalidValueType]))->assertUnprocessable()->assertJsonValidationErrorFor('value_type');
        }

        $this->assertDatabaseCount('discount_types', 0);
    }

    #[DataProvider('provideInvalidFixedValues')]
    public function test_invalid_fixed_value_is_refused(mixed $invalidValue): void
    {
        $this->postType($this->buildTypePayload(['value_type' => 'fixed', 'value' => $invalidValue]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('value');

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_fixed_value_edges_pass(): void
    {
        $this->postType($this->buildTypePayload(['name' => 'Smallest', 'value_type' => 'fixed', 'value' => '0.01']))
            ->assertCreated()
            ->assertJsonPath('discount_type.value', '0.01');
        $this->postType($this->buildTypePayload(['name' => 'Largest', 'value_type' => 'fixed', 'value' => 9999999999.99]))
            ->assertCreated()
            ->assertJsonPath('discount_type.value', '9999999999.99');
    }

    #[DataProvider('provideInvalidPercentValues')]
    public function test_invalid_percent_value_is_refused(mixed $invalidValue): void
    {
        $this->postType($this->buildTypePayload(['value_type' => 'percent', 'value' => $invalidValue]))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('value');

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_percent_value_edges_pass(): void
    {
        $this->postType($this->buildTypePayload(['name' => 'Smallest', 'value_type' => 'percent', 'value' => '0.01']))
            ->assertCreated()
            ->assertJsonPath('discount_type.value', '0.01');
        $this->postType($this->buildTypePayload(['name' => 'Whole', 'value_type' => 'percent', 'value' => 100]))
            ->assertCreated()
            ->assertJsonPath('discount_type.value', '100.00');
    }

    public function test_base_must_be_known(): void
    {
        foreach (['miscellaneous', 'Tuition', null] as $invalidBase) {
            $this->postType($this->buildTypePayload(['base' => $invalidBase]))->assertUnprocessable()->assertJsonValidationErrorFor('base');
        }

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_stages_must_be_a_non_empty_list(): void
    {
        $missingStages = $this->buildTypePayload();
        unset($missingStages['stages']);
        $this->postType($missingStages)->assertUnprocessable()->assertJsonValidationErrorFor('stages');

        foreach ([[], 'jhs', ['first' => 'jhs']] as $invalidStages) {
            $this->postType($this->buildTypePayload(['stages' => $invalidStages]))->assertUnprocessable()->assertJsonValidationErrorFor('stages');
        }

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_unknown_or_repeated_stage_is_refused(): void
    {
        $this->postType($this->buildTypePayload(['stages' => ['elementary']]))->assertUnprocessable()->assertJsonValidationErrorFor('stages.0');
        $this->postType($this->buildTypePayload(['stages' => ['JHS']]))->assertUnprocessable()->assertJsonValidationErrorFor('stages.0');
        $this->postType($this->buildTypePayload(['stages' => ['jhs', 'jhs']]))->assertUnprocessable()->assertJsonValidationErrors(['stages.0', 'stages.1']);

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_college_stage_is_refused_on_create(): void
    {
        $this->postType($this->buildTypePayload(['stages' => ['college']]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['stages.0' => [StoreDiscountTypeRequest::COLLEGE_STAGE_MESSAGE]]);
        $this->postType($this->buildTypePayload(['stages' => ['jhs', 'college']]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['stages.1' => [StoreDiscountTypeRequest::COLLEGE_STAGE_MESSAGE]]);

        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_college_stage_is_refused_on_update(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);

        $this->putType($discountType->id, $this->buildTypePayload(['stages' => ['shs', 'college']]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['stages.1' => [StoreDiscountTypeRequest::COLLEGE_STAGE_MESSAGE]]);

        $this->assertSame(['jhs', 'shs'], $discountType->fresh()->stages);
    }

    public function test_update_locks_source_while_held(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'source' => 'school']);
        $this->assignType(Student::factory()->create(), $discountType);

        $this->putType($discountType->id, $this->buildTypePayload(['source' => 'government']))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['source' => [UpdateDiscountTypeRequest::SOURCE_LOCKED_MESSAGE]]);

        $this->assertSame('school', $discountType->fresh()->source);
    }

    public function test_update_refuses_stage_removal_while_held(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);
        $this->assignType(Student::factory()->create(), $discountType);

        $this->putType($discountType->id, $this->buildTypePayload(['stages' => ['shs']]))
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['stages' => [UpdateDiscountTypeRequest::STAGE_REMOVAL_MESSAGE]]);

        $this->assertSame(['jhs', 'shs'], $discountType->fresh()->stages);
    }

    public function test_update_allows_stage_addition_and_value_changes_while_held(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'stages' => ['jhs']]);
        $this->assignType(Student::factory()->create(), $discountType);

        $this->putType($discountType->id, $this->buildTypePayload([
            'stages' => ['shs', 'jhs'],
            'value_type' => 'fixed',
            'value' => '750',
            'base' => 'total',
        ]))
            ->assertOk()
            ->assertJsonPath('discount_type.stages', ['jhs', 'shs'])
            ->assertJsonPath('discount_type.value_type', 'fixed')
            ->assertJsonPath('discount_type.value', '750.00')
            ->assertJsonPath('discount_type.base', 'total');
    }

    public function test_update_allows_source_and_stage_changes_when_unheld(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'source' => 'school']);

        $this->putType($discountType->id, $this->buildTypePayload(['source' => 'government', 'stages' => ['shs']]))
            ->assertOk()
            ->assertJsonPath('discount_type.source', 'government')
            ->assertJsonPath('discount_type.stages', ['shs']);
    }

    public function test_held_rules_wait_for_valid_inputs(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);
        $this->assignType(Student::factory()->create(), $discountType);

        $stageResponse = $this->putType($discountType->id, $this->buildTypePayload(['stages' => ['college']]))->assertUnprocessable();
        $this->assertSame(['stages.0'], array_keys($stageResponse->json('errors')));

        $sourceResponse = $this->putType($discountType->id, $this->buildTypePayload(['source' => 'bogus']))->assertUnprocessable();
        $this->assertSame(['source'], array_keys($sourceResponse->json('errors')));
        $this->assertNotSame([UpdateDiscountTypeRequest::SOURCE_LOCKED_MESSAGE], $sourceResponse->json('errors.source'));
        $this->assertSame(1, DiscountType::count());
    }
}
