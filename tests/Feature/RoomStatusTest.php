<?php

namespace Tests\Feature;

use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class RoomStatusTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    public function test_deactivate_marks_room_inactive(): void
    {
        $room = $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('room.id', $room->id)
            ->assertJsonPath('room.is_active', false);

        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_reactivate_marks_room_active(): void
    {
        $room = Room::factory()->inactive()->create(['name' => self::ROOM_101]);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id.'/reactivate')
            ->assertOk()
            ->assertJsonPath('room.is_active', true);

        $this->assertTrue($room->fresh()->is_active);
    }

    public function test_deactivate_is_idempotent(): void
    {
        $room = Room::factory()->inactive()->create(['name' => self::ROOM_101]);

        $this->withToken($this->adminToken)
            ->putJson(self::ROOMS_URL.'/'.$room->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('room.is_active', false);

        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_status_routes_return_404_for_missing_id(): void
    {
        $this->withToken($this->adminToken)->putJson(self::ROOMS_URL.'/'.self::MISSING_ID.'/deactivate')->assertNotFound();
        $this->withToken($this->adminToken)->putJson(self::ROOMS_URL.'/'.self::MISSING_ID.'/reactivate')->assertNotFound();
    }

    public function test_deactivated_room_stays_linked_to_its_sections(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $section = $this->createSection(['room_id' => $room->id]);

        $this->withToken($this->adminToken)->putJson(self::ROOMS_URL.'/'.$room->id.'/deactivate')->assertOk();

        $this->assertSame($room->id, $section->fresh()->room_id);
        $this->assertSame(1, $room->sections()->count());
    }

    public function test_delete_room_route_does_not_exist(): void
    {
        $room = $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->deleteJson(self::ROOMS_URL.'/'.$room->id)
            ->assertMethodNotAllowed();

        $this->assertNotNull($room->fresh());
    }
}
