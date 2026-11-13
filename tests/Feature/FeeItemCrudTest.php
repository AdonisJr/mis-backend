<?php

namespace Tests\Feature;

use App\Http\Requests\StoreFeeItemRequest;
use App\Models\FeeItem;
use App\Models\FeeScheduleLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeItemCrudTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    private const FEE_ITEM_KEYS = ['id', 'name', 'category', 'description', 'is_active', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_fee_items_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('fee_items'));
        $this->assertTrue(Schema::hasColumns('fee_items', self::FEE_ITEM_KEYS));

        $feeItemColumns = collect(Schema::getColumns('fee_items'))->keyBy('name');
        foreach (['name', 'category', 'is_active'] as $requiredColumn) {
            $this->assertFalse($feeItemColumns[$requiredColumn]['nullable'], $requiredColumn.' must not be nullable');
        }
        $this->assertTrue($feeItemColumns['description']['nullable']);
    }

    public function test_admin_creates_fee_item(): void
    {
        $createResponse = $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => self::TUITION_FEE, 'category' => FeeItem::CATEGORY_TUITION, 'description' => 'Yearly tuition'])
            ->assertCreated()
            ->assertJsonPath('fee_item.name', self::TUITION_FEE)
            ->assertJsonPath('fee_item.category', FeeItem::CATEGORY_TUITION)
            ->assertJsonPath('fee_item.description', 'Yearly tuition')
            ->assertJsonPath('fee_item.is_active', true);

        $this->assertSame(self::FEE_ITEM_KEYS, array_keys($createResponse->json('fee_item')));
        $this->assertDatabaseHas('fee_items', ['name' => self::TUITION_FEE, 'is_active' => true]);
    }

    public function test_create_rejects_duplicate_name_ignoring_case(): void
    {
        $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);

        foreach (['tuition fee', ' Tuition Fee '] as $duplicateName) {
            $this->withToken($this->adminToken)
                ->postJson(self::FEE_ITEMS_URL, ['name' => $duplicateName, 'category' => FeeItem::CATEGORY_OTHER])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name' => StoreFeeItemRequest::NAME_TAKEN_MESSAGE]);
        }

        $this->assertDatabaseCount('fee_items', 1);
    }

    public function test_create_requires_name_and_category(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'category']);

        $this->assertDatabaseCount('fee_items', 0);
    }

    public function test_create_rejects_unknown_category(): void
    {
        foreach (['Tuition', 'books', 123] as $unknownCategory) {
            $this->withToken($this->adminToken)
                ->postJson(self::FEE_ITEMS_URL, ['name' => self::LIBRARY_FEE, 'category' => $unknownCategory])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['category']);
        }

        $this->assertDatabaseCount('fee_items', 0);
    }

    public function test_create_rejects_oversized_text(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => str_repeat('n', 256), 'category' => FeeItem::CATEGORY_OTHER])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER, 'description' => str_repeat('d', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
        $this->assertDatabaseCount('fee_items', 0);

        $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => str_repeat('n', 255), 'category' => FeeItem::CATEGORY_OTHER, 'description' => str_repeat('d', 1000)])
            ->assertCreated();
    }

    public function test_create_ignores_is_active_in_body(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER, 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('fee_item.is_active', true);
    }

    public function test_admin_updates_fee_item(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.$feeItem->id, ['name' => self::LIBRARY_FEE, 'category' => FeeItem::CATEGORY_MISCELLANEOUS, 'description' => 'Books'])
            ->assertOk()
            ->assertJsonPath('fee_item.name', self::LIBRARY_FEE)
            ->assertJsonPath('fee_item.category', FeeItem::CATEGORY_MISCELLANEOUS)
            ->assertJsonPath('fee_item.description', 'Books');

        $this->assertDatabaseHas('fee_items', ['id' => $feeItem->id, 'name' => self::LIBRARY_FEE]);
    }

    public function test_update_allows_own_name_in_another_case(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.$feeItem->id, ['name' => 'TUITION FEE', 'category' => FeeItem::CATEGORY_TUITION])
            ->assertOk()
            ->assertJsonPath('fee_item.name', 'TUITION FEE');
    }

    public function test_update_rejects_name_of_another_item_ignoring_case(): void
    {
        $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $otherFeeItem = $this->createFeeItem(self::LIBRARY_FEE);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.$otherFeeItem->id, ['name' => 'tuition fee', 'category' => FeeItem::CATEGORY_MISCELLANEOUS])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreFeeItemRequest::NAME_TAKEN_MESSAGE]);

        $this->assertSame(self::LIBRARY_FEE, $otherFeeItem->fresh()->name);
    }

    public function test_update_keeps_is_active(): void
    {
        $inactiveFeeItem = FeeItem::factory()->inactive()->create(['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER]);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.$inactiveFeeItem->id, ['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER, 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('fee_item.is_active', false);

        $this->assertFalse($inactiveFeeItem->fresh()->is_active);
    }

    public function test_update_validates_before_lookup(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.self::MISSING_ID, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'category']);
    }

    public function test_update_missing_id_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.self::MISSING_ID, ['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER])
            ->assertNotFound();
    }

    public function test_used_fee_item_cannot_be_deleted_at_database_level(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('fee_schedule_lines'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => [$foreignKey['foreign_table'], $foreignKey['on_delete']]])
            ->all();
        $this->assertSame(['fee_items', 'restrict'], $foreignKeys['fee_item_id']);

        $feeScheduleLine = FeeScheduleLine::factory()->create();

        try {
            DB::table('fee_items')->where('id', $feeScheduleLine->fee_item_id)->delete();
            $this->fail('The restrict foreign key must refuse the delete.');
        } catch (QueryException) {
            $this->assertDatabaseHas('fee_items', ['id' => $feeScheduleLine->fee_item_id]);
        }
    }

    public function test_list_returns_paginated_fee_items_ordered_by_name(): void
    {
        foreach (range(16, 1) as $itemNumber) {
            $this->createFeeItem(sprintf('Fee %02d', $itemNumber));
        }

        $firstPageResponse = $this->withToken($this->adminToken)->getJson(self::FEE_ITEMS_URL)->assertOk()->assertJsonCount(15, 'fee_items');

        $this->assertSame('Fee 01', $firstPageResponse->json('fee_items.0.name'));
        $this->assertSame(self::FEE_ITEM_KEYS, array_keys($firstPageResponse->json('fee_items.0')));
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 15, 'total' => 16], $firstPageResponse->json('meta'));
        $this->withToken($this->adminToken)
            ->getJson(self::FEE_ITEMS_URL.'?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'fee_items')
            ->assertJsonPath('fee_items.0.name', 'Fee 16');
    }

    public function test_list_searches_every_word_in_name(): void
    {
        $this->createSearchFixtures();

        $this->assertSame([self::LABORATORY_FEE, self::LIBRARY_FEE, self::TUITION_FEE], $this->fetchNames(['search' => 'fee']));
        $this->assertSame([self::LABORATORY_FEE], $this->fetchNames(['search' => 'lab FEE']));
        $this->assertSame([self::LIBRARY_FEE], $this->fetchNames(['search' => 'LIBRARY']));
        $this->assertSame([self::ID_CARD], $this->fetchNames(['search' => 'card']));
        $this->assertSame([], $this->fetchNames(['search' => 'tuition lab']));
    }

    public function test_list_filters_by_category(): void
    {
        $this->createSearchFixtures();

        $this->assertSame([self::TUITION_FEE], $this->fetchNames(['category' => FeeItem::CATEGORY_TUITION]));
        $this->assertSame([self::ID_CARD], $this->fetchNames(['category' => FeeItem::CATEGORY_OTHER]));
        $this->assertSame([self::LABORATORY_FEE, self::LIBRARY_FEE], $this->fetchNames(['category' => FeeItem::CATEGORY_MISCELLANEOUS]));
    }

    public function test_list_filters_by_status_and_lists_inactive_by_default(): void
    {
        $this->createSearchFixtures();
        FeeItem::where('name', self::ID_CARD)->update(['is_active' => false]);

        $this->assertCount(4, $this->fetchNames([]));
        $this->assertSame([self::ID_CARD], $this->fetchNames(['status' => 'inactive']));
        $this->assertCount(3, $this->fetchNames(['status' => 'active']));
    }

    public function test_list_rejects_invalid_filters(): void
    {
        foreach ([['category', 'books'], ['status', 'archived'], ['search', str_repeat('s', 256)]] as [$filterField, $filterValue]) {
            $this->withToken($this->adminToken)
                ->getJson(self::FEE_ITEMS_URL.'?'.http_build_query([$filterField => $filterValue]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$filterField]);
        }
    }

    public function test_admin_deactivates_and_reactivates_fee_item(): void
    {
        $feeItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);
        $feeItemUrl = self::FEE_ITEMS_URL.'/'.$feeItem->id;

        foreach ([false, false, true, true] as $isActiveAfter) {
            $actionUrl = $feeItemUrl.($isActiveAfter ? '/reactivate' : '/deactivate');

            $this->withToken($this->adminToken)
                ->putJson($actionUrl)
                ->assertOk()
                ->assertJsonPath('fee_item.is_active', $isActiveAfter);
            $this->assertSame($isActiveAfter, $feeItem->fresh()->is_active);
        }
    }

    public function test_status_actions_ignore_body(): void
    {
        $feeItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);

        $this->withToken($this->adminToken)
            ->putJson(self::FEE_ITEMS_URL.'/'.$feeItem->id.'/deactivate', ['name' => 'Changed', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('fee_item.is_active', false)
            ->assertJsonPath('fee_item.name', self::ID_CARD);

        $this->assertSame(self::ID_CARD, $feeItem->fresh()->name);
    }

    public function test_status_routes_missing_id_return_404(): void
    {
        $this->withToken($this->adminToken)->putJson(self::FEE_ITEMS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::FEE_ITEMS_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_deactivated_fee_item_stays_on_existing_schedules(): void
    {
        $feeItem = $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $feeSchedule = $this->createSchedule($this->buildKeys(self::GRADE_7), [[$feeItem, '1500.50']]);

        $this->withToken($this->adminToken)->putJson(self::FEE_ITEMS_URL.'/'.$feeItem->id.'/deactivate')->assertOk();

        $this->withToken($this->adminToken)
            ->getJson(self::FEE_SCHEDULES_URL.'/'.$feeSchedule->id)
            ->assertOk()
            ->assertJsonPath('fee_schedule.lines_count', 1)
            ->assertJsonPath('fee_schedule.total', '1500.50')
            ->assertJsonPath('fee_schedule.lines.0.fee_item.is_active', false);
    }

    private function createSearchFixtures(): void
    {
        $this->createFeeItem(self::TUITION_FEE, FeeItem::CATEGORY_TUITION);
        $this->createFeeItem(self::LIBRARY_FEE);
        $this->createFeeItem(self::LABORATORY_FEE);
        $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return list<string>
     */
    private function fetchNames(array $queryParams): array
    {
        return $this->withToken($this->adminToken)
            ->getJson(self::FEE_ITEMS_URL.'?'.http_build_query($queryParams))
            ->assertOk()
            ->json('fee_items.*.name');
    }
}
