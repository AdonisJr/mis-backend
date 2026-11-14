<?php

namespace Tests\Feature;

use App\Http\Controllers\DiscountTypeController;
use App\Models\DiscountType;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\InteractsWithDiscounts;
use Tests\TestCase;

class DiscountTypeCrudTest extends TestCase
{
    use InteractsWithDiscounts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_discount_tables_have_expected_columns(): void
    {
        $this->assertSame(
            ['id', 'name', 'description', 'source', 'value_type', 'value', 'base', 'stages', 'is_active', 'created_at', 'updated_at'],
            Schema::getColumnListing('discount_types'),
        );
        $this->assertSame(
            ['id', 'student_id', 'school_year_id', 'discount_type_id', 'reference_no', 'remarks', 'created_at', 'updated_at'],
            Schema::getColumnListing('student_discounts'),
        );

        $typeColumns = collect(Schema::getColumns('discount_types'))->keyBy('name');

        foreach (['name', 'source', 'value_type', 'value', 'base', 'stages', 'is_active'] as $requiredColumn) {
            $this->assertFalse($typeColumns[$requiredColumn]['nullable'], $requiredColumn);
        }

        $this->assertTrue($typeColumns['description']['nullable']);

        $assignmentColumns = collect(Schema::getColumns('student_discounts'))->keyBy('name');
        $this->assertTrue($assignmentColumns['reference_no']['nullable']);
        $this->assertTrue($assignmentColumns['remarks']['nullable']);
        $this->assertFalse($assignmentColumns['discount_type_id']['nullable']);
    }

    public function test_constraints_restrict_deletes_and_keep_assignments_unique(): void
    {
        $assignmentForeignKeys = collect(Schema::getForeignKeys('student_discounts'))->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])->all();

        $this->assertEquals([
            'student_id' => ['students', 'restrict'],
            'school_year_id' => ['school_years', 'restrict'],
            'discount_type_id' => ['discount_types', 'restrict'],
        ], $assignmentForeignKeys);

        $held = StudentDiscount::factory()->create();

        $this->assertThrows(fn () => DB::table('students')->where('id', $held->student_id)->delete(), QueryException::class);
        $this->assertThrows(fn () => DB::table('school_years')->where('id', $held->school_year_id)->delete(), QueryException::class);
        $this->assertThrows(fn () => DB::table('discount_types')->where('id', $held->discount_type_id)->delete(), QueryException::class);
        $this->assertThrows(fn () => StudentDiscount::create($held->only(['student_id', 'school_year_id', 'discount_type_id'])), QueryException::class);
        $this->assertDatabaseCount('student_discounts', 1);
    }

    public function test_admin_creates_discount_type(): void
    {
        $createResponse = $this->postType($this->buildTypePayload(['description' => 'For siblings']))
            ->assertCreated()
            ->assertJsonPath('discount_type.name', self::SIBLING_TYPE)
            ->assertJsonPath('discount_type.description', 'For siblings')
            ->assertJsonPath('discount_type.source', 'school')
            ->assertJsonPath('discount_type.value_type', 'percent')
            ->assertJsonPath('discount_type.value', '10.00')
            ->assertJsonPath('discount_type.base', 'tuition')
            ->assertJsonPath('discount_type.stages', ['jhs', 'shs'])
            ->assertJsonPath('discount_type.is_active', true);

        $this->assertSame(self::DISCOUNT_TYPE_KEYS, array_keys($createResponse->json('discount_type')));
        $this->assertDatabaseCount('discount_types', 1);
    }

    public function test_create_stores_stages_in_canonical_order(): void
    {
        $this->postType($this->buildTypePayload(['stages' => ['shs', 'jhs']]))
            ->assertCreated()
            ->assertJsonPath('discount_type.stages', ['jhs', 'shs']);

        $this->assertSame(['jhs', 'shs'], DiscountType::first()->stages);
    }

    public function test_create_ignores_is_active_and_unknown_keys(): void
    {
        $this->postType($this->buildTypePayload(['is_active' => false, 'id' => 999, 'unknown' => 'value']))
            ->assertCreated()
            ->assertJsonPath('discount_type.is_active', true)
            ->assertJsonMissingPath('discount_type.unknown');

        $this->assertNotSame(999, DiscountType::first()->id);
        $this->assertTrue(DiscountType::first()->is_active);
    }

    public function test_show_returns_discount_type(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE, 'description' => 'For siblings']);

        $showResponse = $this->withToken($this->adminToken)
            ->getJson(self::DISCOUNT_TYPES_URL.'/'.$discountType->id)
            ->assertOk()
            ->assertJsonPath('discount_type.id', $discountType->id)
            ->assertJsonPath('discount_type.description', 'For siblings')
            ->assertJsonPath('discount_type.value', '1000.00');

        $this->assertSame(self::DISCOUNT_TYPE_KEYS, array_keys($showResponse->json('discount_type')));
    }

    public function test_show_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->getJson(self::DISCOUNT_TYPES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_admin_updates_discount_type(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);
        $discountType->forceFill(['is_active' => false])->save();

        $this->putType($discountType->id, $this->buildTypePayload([
            'name' => self::SCHOLARSHIP_TYPE,
            'description' => 'Top students',
            'source' => 'government',
            'value_type' => 'fixed',
            'value' => '2500.5',
            'base' => 'total',
            'stages' => ['shs'],
        ]))
            ->assertOk()
            ->assertJsonPath('discount_type.name', self::SCHOLARSHIP_TYPE)
            ->assertJsonPath('discount_type.value', '2500.50')
            ->assertJsonPath('discount_type.stages', ['shs'])
            ->assertJsonPath('discount_type.is_active', false);

        $storedType = $discountType->fresh();
        $this->assertSame('government', $storedType->source);
        $this->assertSame('total', $storedType->base);
        $this->assertFalse($storedType->is_active);
    }

    public function test_update_allows_own_name_in_another_case(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);

        $this->putType($discountType->id, $this->buildTypePayload(['name' => strtoupper(self::SIBLING_TYPE)]))
            ->assertOk()
            ->assertJsonPath('discount_type.name', strtoupper(self::SIBLING_TYPE));
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->putType(self::MISSING_ID, $this->buildTypePayload(['name' => self::VOUCHER_TYPE]))->assertNotFound();
        $this->assertDatabaseCount('discount_types', 0);
    }

    public function test_admin_deactivates_and_reactivates_discount_type(): void
    {
        $discountType = $this->createType();
        $typeUrl = self::DISCOUNT_TYPES_URL.'/'.$discountType->id;

        $this->withToken($this->adminToken)->putJson($typeUrl.'/deactivate')->assertOk()->assertJsonPath('discount_type.is_active', false);
        $this->withToken($this->adminToken)->putJson($typeUrl.'/deactivate')->assertOk()->assertJsonPath('discount_type.is_active', false);
        $this->assertFalse($discountType->fresh()->is_active);

        $this->withToken($this->adminToken)->putJson($typeUrl.'/reactivate')->assertOk()->assertJsonPath('discount_type.is_active', true);
        $this->withToken($this->adminToken)->putJson($typeUrl.'/reactivate')->assertOk()->assertJsonPath('discount_type.is_active', true);
        $this->assertTrue($discountType->fresh()->is_active);
    }

    public function test_status_actions_ignore_body(): void
    {
        $discountType = $this->createType(['name' => self::SIBLING_TYPE]);
        $typeUrl = self::DISCOUNT_TYPES_URL.'/'.$discountType->id;

        $this->withToken($this->adminToken)->putJson($typeUrl.'/deactivate', ['name' => 'Changed', 'is_active' => true])->assertOk();

        $storedType = $discountType->fresh();
        $this->assertSame(self::SIBLING_TYPE, $storedType->name);
        $this->assertFalse($storedType->is_active);
    }

    public function test_status_routes_missing_id_return_404(): void
    {
        $this->withToken($this->adminToken)->putJson(self::DISCOUNT_TYPES_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::DISCOUNT_TYPES_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_admin_deletes_unheld_discount_type(): void
    {
        $discountType = $this->createType();

        $this->withToken($this->adminToken)->deleteJson(self::DISCOUNT_TYPES_URL.'/'.$discountType->id)->assertNoContent();

        $this->assertNull($discountType->fresh());
    }

    public function test_delete_held_discount_type_returns_422(): void
    {
        $activeType = $this->createType();
        $inactiveType = DiscountType::factory()->inactive()->create();
        $this->assignType(Student::factory()->create(), $activeType);
        $this->assignType(Student::factory()->create(), $inactiveType);

        foreach ([$activeType, $inactiveType] as $heldType) {
            $this->withToken($this->adminToken)
                ->deleteJson(self::DISCOUNT_TYPES_URL.'/'.$heldType->id)
                ->assertUnprocessable()
                ->assertExactJson(['message' => DiscountTypeController::HELD_DELETE_MESSAGE]);

            $this->assertNotNull($heldType->fresh());
        }

        $this->assertDatabaseCount('student_discounts', 2);
    }

    public function test_delete_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)->deleteJson(self::DISCOUNT_TYPES_URL.'/'.self::MISSING_ID)->assertNotFound();
    }

    public function test_list_returns_types_ordered_by_name(): void
    {
        $this->createType(['name' => 'Charlie']);
        $this->createType(['name' => 'Alpha']);
        $this->createType(['name' => 'Bravo']);

        $listResponse = $this->fetchTypeList()->assertOk()->assertJsonCount(3, 'discount_types');

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], collect($listResponse->json('discount_types'))->pluck('name')->all());
        $this->assertSame(self::DISCOUNT_TYPE_KEYS, array_keys($listResponse->json('discount_types.0')));
        $listResponse->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 15, 'total' => 3]);
    }

    public function test_list_searches_every_word_in_name(): void
    {
        $this->createType(['name' => self::SIBLING_TYPE]);
        $this->createType(['name' => self::SCHOLARSHIP_TYPE]);

        $this->fetchTypeList('?search=sibl')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::SIBLING_TYPE);
        $this->fetchTypeList('?search=ACAD%20scholar')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::SCHOLARSHIP_TYPE);
        $this->fetchTypeList('?search=sibl%20scholar')->assertOk()->assertJsonCount(0, 'discount_types');
    }

    public function test_list_filters_by_source(): void
    {
        $this->createType(['name' => self::VOUCHER_TYPE, 'source' => 'government']);
        $this->createType(['name' => self::SIBLING_TYPE, 'source' => 'school']);

        $this->fetchTypeList('?source=government')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::VOUCHER_TYPE);
        $this->fetchTypeList('?source=school')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::SIBLING_TYPE);
    }

    public function test_list_filters_by_status_and_lists_inactive_by_default(): void
    {
        $this->createType(['name' => self::SIBLING_TYPE]);
        DiscountType::factory()->inactive()->create(['name' => self::VOUCHER_TYPE]);

        $this->fetchTypeList()->assertOk()->assertJsonCount(2, 'discount_types');
        $this->fetchTypeList('?status=active')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::SIBLING_TYPE);
        $this->fetchTypeList('?status=inactive')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('discount_types.0.name', self::VOUCHER_TYPE);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->fetchTypeList('?source=private')->assertUnprocessable()->assertJsonValidationErrorFor('source');
        $this->fetchTypeList('?status=archived')->assertUnprocessable()->assertJsonValidationErrorFor('status');
        $this->fetchTypeList('?search='.str_repeat('a', 256))->assertUnprocessable()->assertJsonValidationErrorFor('search');
        $this->fetchTypeList('?search[]=a')->assertUnprocessable()->assertJsonValidationErrorFor('search');
    }

    public function test_list_paginates_fifteen_per_page(): void
    {
        DiscountType::factory()->count(16)->create();

        $this->fetchTypeList()->assertOk()->assertJsonCount(15, 'discount_types')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 2);
        $this->fetchTypeList('?page=2')->assertOk()->assertJsonCount(1, 'discount_types')->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_query_count_is_independent_of_rows(): void
    {
        DiscountType::factory()->create();

        DB::enableQueryLog();
        $this->fetchTypeList()->assertOk()->assertJsonCount(1, 'discount_types');
        $singleRowCount = $this->countQueriesWithoutTokens();

        DiscountType::factory()->count(5)->create();
        DB::flushQueryLog();
        $this->app['auth']->forgetGuards();
        $this->fetchTypeList()->assertOk()->assertJsonCount(6, 'discount_types');

        $this->assertSame($singleRowCount, $this->countQueriesWithoutTokens());
    }

    private function fetchTypeList(string $queryString = ''): TestResponse
    {
        return $this->withToken($this->adminToken)->getJson(self::DISCOUNT_TYPES_URL.$queryString);
    }
}
