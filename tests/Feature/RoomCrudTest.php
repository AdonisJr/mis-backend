<?php

namespace Tests\Feature;

use App\Http\Requests\StoreRoomRequest;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class RoomCrudTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideInvalidFields(): array
    {
        return [
            'name missing' => [['name' => null], 'name'],
            'name too long' => [['name' => str_repeat('A', 51)], 'name'],
            'name array' => [['name' => ['Rm']], 'name'],
            'building too long' => [['building' => str_repeat('B', 101)], 'building'],
            'building number' => [['building' => 123], 'building'],
            'capacity zero' => [['capacity' => 0], 'capacity'],
            'capacity fraction' => [['capacity' => 1.5], 'capacity'],
            'capacity text' => [['capacity' => 'abc'], 'capacity'],
            'capacity negative' => [['capacity' => -1], 'capacity'],
            'capacity boolean' => [['capacity' => true], 'capacity'],
            'type missing' => [['type' => null], 'type'],
            'type unknown' => [['type' => 'office'], 'type'],
            'type wrong case' => [['type' => 'Classroom'], 'type'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEveryType(): array
    {
        return [
            'classroom' => [Room::TYPE_CLASSROOM],
            'laboratory' => [Room::TYPE_LABORATORY],
            'other' => [Room::TYPE_OTHER],
        ];
    }

    public function test_rooms_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('rooms', ['id', 'name', 'building', 'capacity', 'type', 'is_active', 'created_at', 'updated_at']));

        $roomColumns = collect(Schema::getColumns('rooms'))->keyBy('name');
        $this->assertFalse($roomColumns['name']['nullable']);
        $this->assertFalse($roomColumns['type']['nullable']);
        $this->assertTrue($roomColumns['building']['nullable']);
        $this->assertTrue($roomColumns['capacity']['nullable']);
    }

    public function test_admin_creates_room(): void
    {
        $createResponse = $this->withToken($this->adminToken)->postJson(self::ROOMS_URL, $this->buildRoomPayload());

        $createResponse->assertCreated()
            ->assertJsonPath('room.name', self::ROOM_101)
            ->assertJsonPath('room.building', self::MAIN_BUILDING)
            ->assertJsonPath('room.capacity', 40)
            ->assertJsonPath('room.type', Room::TYPE_CLASSROOM)
            ->assertJsonPath('room.is_active', true);
        $this->assertEqualsCanonicalizing(self::ROOM_KEYS, array_keys($createResponse->json('room')));
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_create_stores_optional_fields_as_null(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, Arr::except($this->buildRoomPayload(), ['building', 'capacity']))
            ->assertCreated()
            ->assertJsonPath('room.building', null)
            ->assertJsonPath('room.capacity', null);
    }

    public function test_create_ignores_is_active_in_body(): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, $this->buildRoomPayload(['is_active' => false]))
            ->assertCreated()
            ->assertJsonPath('room.is_active', true);

        $this->assertTrue(Room::firstOrFail()->is_active);
    }

    public function test_create_rejects_name_taken_ignoring_case(): void
    {
        $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, $this->buildRoomPayload(['name' => ' rm 101 ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreRoomRequest::NAME_TAKEN_MESSAGE]);

        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_create_accepts_field_limits(): void
    {
        $limitPayload = $this->buildRoomPayload(['name' => str_repeat('A', 50), 'building' => str_repeat('B', 100), 'capacity' => 1]);

        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, $limitPayload)
            ->assertCreated()
            ->assertJsonPath('room.name', $limitPayload['name'])
            ->assertJsonPath('room.building', $limitPayload['building'])
            ->assertJsonPath('room.capacity', 1);
    }

    public function test_update_changes_room_fields(): void
    {
        $room = $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id, $this->buildRoomPayload(['name' => self::LAB_A, 'building' => null, 'capacity' => null, 'type' => Room::TYPE_LABORATORY]))
            ->assertOk()
            ->assertJsonPath('room.id', $room->id)
            ->assertJsonPath('room.name', self::LAB_A)
            ->assertJsonPath('room.building', null)
            ->assertJsonPath('room.capacity', null)
            ->assertJsonPath('room.type', Room::TYPE_LABORATORY);

        $this->assertSame(self::LAB_A, $room->fresh()->name);
    }

    public function test_update_keeps_own_name_with_new_case(): void
    {
        $room = $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id, $this->buildRoomPayload(['name' => 'RM 101']))
            ->assertOk()
            ->assertJsonPath('room.name', 'RM 101');
    }

    public function test_update_rejects_name_of_another_room_ignoring_case(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $this->createRoom(self::LAB_A);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id, $this->buildRoomPayload(['name' => 'lab a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => StoreRoomRequest::NAME_TAKEN_MESSAGE]);

        $this->assertSame(self::ROOM_101, $room->fresh()->name);
    }

    public function test_update_ignores_is_active_in_body(): void
    {
        $room = Room::factory()->inactive()->create(['name' => self::ROOM_101]);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id, $this->buildRoomPayload(['is_active' => true]))
            ->assertOk()
            ->assertJsonPath('room.is_active', false);

        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_update_missing_id_returns_404(): void
    {
        config(['app.debug' => false]);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.self::MISSING_ID, $this->buildRoomPayload())
            ->assertNotFound()
            ->assertExactJson(['message' => 'No query results for model ['.Room::class.'] '.self::MISSING_ID]);
    }

    #[DataProvider('provideEveryType')]
    public function test_create_accepts_every_type(string $type): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, $this->buildRoomPayload(['type' => $type]))
            ->assertCreated()
            ->assertJsonPath('room.type', $type);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('provideInvalidFields')]
    public function test_create_rejects_invalid_fields(array $overrides, string $errorField): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::ROOMS_URL, $this->buildRoomPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertDatabaseCount('rooms', 0);
    }
}
