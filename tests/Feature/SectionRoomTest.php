<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSectionRequest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class SectionRoomTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideInvalidRoomIds(): array
    {
        return [
            'missing room' => [999],
            'text' => ['abc'],
            'fraction' => ['1.5'],
        ];
    }

    public function test_create_with_active_room_returns_room_summary(): void
    {
        $room = $this->createRoom(self::ROOM_101, ['building' => self::MAIN_BUILDING]);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => $room->id]))
            ->assertCreated()
            ->assertJsonPath('section.room_id', $room->id)
            ->assertJsonPath('section.room', ['id' => $room->id, 'name' => self::ROOM_101, 'building' => self::MAIN_BUILDING]);
    }

    public function test_create_rejects_inactive_room(): void
    {
        $room = Room::factory()->inactive()->create(['name' => self::ROOM_101]);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => $room->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id' => StoreSectionRequest::ROOM_INACTIVE_MESSAGE]);

        $this->assertDatabaseCount('sections', 0);
    }

    public function test_update_keeps_unchanged_inactive_room(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $section = $this->createSection(['name' => 'A', 'room_id' => $room->id]);
        $room->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'capacity' => 45, 'room_id' => $room->id]))
            ->assertOk()
            ->assertJsonPath('section.room_id', $room->id)
            ->assertJsonPath('section.room.id', $room->id);
    }

    public function test_update_rejects_switch_to_inactive_room(): void
    {
        $activeRoom = $this->createRoom(self::ROOM_101);
        $inactiveRoom = Room::factory()->inactive()->create(['name' => self::LAB_A]);
        $section = $this->createSection(['name' => 'A', 'room_id' => $activeRoom->id]);

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => $inactiveRoom->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id' => StoreSectionRequest::ROOM_INACTIVE_MESSAGE]);

        $this->assertSame($activeRoom->id, $section->fresh()->room_id);
    }

    public function test_update_clears_room_even_when_it_is_inactive(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $section = $this->createSection(['name' => 'A', 'room_id' => $room->id]);
        $room->forceFill(['is_active' => false])->save();

        $this->withToken($this->adminToken)
            ->putJson(self::SECTIONS_URL.'/'.$section->id, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => null]))
            ->assertOk()
            ->assertJsonPath('section.room_id', null)
            ->assertJsonPath('section.room', null);
    }

    public function test_create_accepts_numeric_string_room_id(): void
    {
        $room = $this->createRoom(self::ROOM_101);

        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => (string) $room->id]))
            ->assertCreated()
            ->assertJsonPath('section.room_id', $room->id);
    }

    public function test_list_filters_by_room(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $this->createSection(['name' => 'In Room', 'room_id' => $room->id]);
        $this->createSection(['name' => 'Elsewhere']);

        $listResponse = $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&room_id='.$room->id)
            ->assertOk();

        $this->assertSame(['In Room'], array_column($listResponse->json('sections'), 'name'));
    }

    public function test_list_rejects_invalid_room_filter(): void
    {
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&room_id=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
        $this->withToken($this->adminToken)
            ->getJson(self::SECTIONS_URL.'?school_year_id='.$this->schoolYear->id.'&room_id='.self::MISSING_ID)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_registrar_cannot_assign_room_or_adviser(): void
    {
        $room = $this->createRoom(self::ROOM_101);
        $teacher = $this->createTeacher();

        $this->withToken($this->createRegistrarToken())
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => $room->id, 'adviser_id' => $teacher->id]))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertDatabaseCount('sections', 0);
        $this->assertSame(1, User::activeTeachers()->count());
    }

    #[DataProvider('provideInvalidRoomIds')]
    public function test_create_rejects_invalid_room_id(mixed $roomId): void
    {
        $this->withToken($this->adminToken)
            ->postJson(self::SECTIONS_URL, $this->buildSectionPayload(self::GRADE_7, ['name' => 'A', 'room_id' => $roomId]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);

        $this->assertDatabaseCount('sections', 0);
    }
}
