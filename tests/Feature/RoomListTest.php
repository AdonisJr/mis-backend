<?php

namespace Tests\Feature;

use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class RoomListTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    private const PAGE_SIZE = 15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidFilters(): array
    {
        return [
            'type' => ['type=office', 'type'],
            'status' => ['status=all', 'status'],
            'search' => ['search='.str_repeat('a', 256), 'search'],
        ];
    }

    public function test_list_paginates_sixteen_rooms_ordered_by_name(): void
    {
        foreach (range(1, 16) as $roomNumber) {
            Room::factory()->create(['name' => sprintf('PG%02d', $roomNumber)]);
        }

        $firstPage = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL)->assertOk();
        $secondPage = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?page=2')->assertOk();

        $this->assertCount(self::PAGE_SIZE, $firstPage->json('rooms'));
        $this->assertSame('PG01', $firstPage->json('rooms.0.name'));
        $this->assertSame(['PG16'], array_column($secondPage->json('rooms'), 'name'));
        $firstPage->assertJsonPath('meta', ['current_page' => 1, 'last_page' => 2, 'per_page' => self::PAGE_SIZE, 'total' => 16]);
    }

    public function test_list_searches_name(): void
    {
        $this->createRoom(self::ROOM_101);
        $this->createRoom(self::LAB_A);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?search=lab')->assertOk();

        $this->assertSame([self::LAB_A], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_list_searches_building(): void
    {
        $this->createRoom(self::ROOM_101, ['building' => self::MAIN_BUILDING]);
        $this->createRoom(self::LAB_A, ['building' => 'Science Annex']);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?search=annex')->assertOk();

        $this->assertSame([self::LAB_A], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_list_search_words_must_all_match(): void
    {
        $this->createRoom(self::ROOM_101, ['building' => self::MAIN_BUILDING]);
        $this->createRoom(self::LAB_A, ['building' => self::MAIN_BUILDING]);

        $matchResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?search=main 101')->assertOk();
        $missResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?search=main annex')->assertOk();

        $this->assertSame([self::ROOM_101], array_column($matchResponse->json('rooms'), 'name'));
        $this->assertSame([], $missResponse->json('rooms'));
    }

    public function test_list_filters_by_type(): void
    {
        $this->createRoom(self::ROOM_101);
        Room::factory()->laboratory()->create(['name' => self::LAB_A]);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?type='.Room::TYPE_LABORATORY)->assertOk();

        $this->assertSame([self::LAB_A], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_list_filters_active_rooms(): void
    {
        $this->createRoom(self::ROOM_101);
        Room::factory()->inactive()->create(['name' => self::LAB_A]);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?status=active')->assertOk();

        $this->assertSame([self::ROOM_101], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_list_filters_inactive_rooms(): void
    {
        $this->createRoom(self::ROOM_101);
        Room::factory()->inactive()->create(['name' => self::LAB_A]);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL.'?status=inactive')->assertOk();

        $this->assertSame([self::LAB_A], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_list_includes_inactive_rooms_by_default(): void
    {
        $this->createRoom(self::ROOM_101);
        Room::factory()->inactive()->create(['name' => self::LAB_A]);

        $listResponse = $this->withToken($this->adminToken)->getJson(self::ROOMS_URL)->assertOk();

        $this->assertSame([self::LAB_A, self::ROOM_101], array_column($listResponse->json('rooms'), 'name'));
    }

    public function test_active_list_is_unpaginated_and_skips_inactive_rooms(): void
    {
        Room::factory()->count(20)->create();
        Room::factory()->inactive()->create();

        $activeResponse = $this->withToken($this->adminToken)->getJson(self::ACTIVE_ROOMS_URL)->assertOk();

        $this->assertCount(20, $activeResponse->json('rooms'));
        $this->assertSame(['rooms'], array_keys($activeResponse->json()));
        $this->assertEqualsCanonicalizing(self::ROOM_KEYS, array_keys($activeResponse->json('rooms.0')));
    }

    public function test_active_list_is_ordered_by_name(): void
    {
        $this->createRoom(self::ROOM_101);
        $this->createRoom(self::LAB_A);

        $activeResponse = $this->withToken($this->adminToken)->getJson(self::ACTIVE_ROOMS_URL)->assertOk();

        $this->assertSame([self::LAB_A, self::ROOM_101], array_column($activeResponse->json('rooms'), 'name'));
    }

    #[DataProvider('provideInvalidFilters')]
    public function test_list_rejects_invalid_filters(string $queryString, string $errorField): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::ROOMS_URL.'?'.$queryString)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);
    }
}
