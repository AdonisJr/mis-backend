<?php

namespace Tests\Feature;

use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithRooms;
use Tests\TestCase;

class RoomAccessTest extends TestCase
{
    use InteractsWithRooms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideWriteRoutes(): array
    {
        return [
            'create' => ['POST', self::ROOMS_URL],
            'update' => ['PUT', self::ROOMS_URL.'/{room}'],
            'deactivate' => ['PUT', self::ROOMS_URL.'/{room}/deactivate'],
            'reactivate' => ['PUT', self::ROOMS_URL.'/{room}/reactivate'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideReadRoutes(): array
    {
        return [
            'list' => ['GET', self::ROOMS_URL],
            'active list' => ['GET', self::ACTIVE_ROOMS_URL],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideEveryRoute(): array
    {
        return [...self::provideReadRoutes(), ...self::provideWriteRoutes()];
    }

    public function test_admin_can_use_every_room_endpoint(): void
    {
        $token = $this->adminToken;
        $createdRoomId = $this->withToken($token)
            ->postJson(self::ROOMS_URL, $this->buildRoomPayload())
            ->assertCreated()
            ->json('room.id');
        $roomUrl = self::ROOMS_URL.'/'.$createdRoomId;

        $this->withToken($token)->getJson(self::ROOMS_URL)->assertOk()->assertJsonPath('rooms.0.name', self::ROOM_101);
        $this->withToken($token)->getJson(self::ACTIVE_ROOMS_URL)->assertOk()->assertJsonPath('rooms.0.name', self::ROOM_101);
        $this->withToken($token)->putJson($roomUrl, $this->buildRoomPayload(['name' => 'Renamed']))->assertOk()->assertJsonPath('room.name', 'Renamed');
        $this->withToken($token)->putJson($roomUrl.'/deactivate')->assertOk()->assertJsonPath('room.is_active', false);
        $this->withToken($token)->putJson($roomUrl.'/reactivate')->assertOk()->assertJsonPath('room.is_active', true);
    }

    public function test_registrar_can_read_both_room_lists(): void
    {
        $registrarToken = $this->createRegistrarToken();

        $this->withToken($registrarToken)->getJson(self::ROOMS_URL)->assertOk();
        $this->withToken($registrarToken)->getJson(self::ACTIVE_ROOMS_URL)->assertOk();
    }

    #[DataProvider('provideWriteRoutes')]
    public function test_registrar_gets_403_on_room_writes(string $method, string $uriPattern): void
    {
        $targetRoom = $this->createRoom(self::ROOM_101);

        $this->withToken($this->createRegistrarToken())
            ->json($method, strtr($uriPattern, ['{room}' => $targetRoom->id]), $this->buildRoomPayload(['name' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertUnchanged($targetRoom);
    }

    #[DataProvider('provideEveryRoute')]
    public function test_cashier_gets_403_on_room_routes(string $method, string $uriPattern): void
    {
        $targetRoom = $this->createRoom(self::ROOM_101);

        $this->withToken($this->createCashierToken())
            ->json($method, strtr($uriPattern, ['{room}' => $targetRoom->id]), $this->buildRoomPayload(['name' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertUnchanged($targetRoom);
    }

    #[DataProvider('provideEveryRoute')]
    public function test_role_less_user_gets_403_on_room_routes(string $method, string $uriPattern): void
    {
        $targetRoom = $this->createRoom(self::ROOM_101);

        $this->withToken($this->createRoleLessToken())
            ->json($method, strtr($uriPattern, ['{room}' => $targetRoom->id]), $this->buildRoomPayload(['name' => 'CHANGED']))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertUnchanged($targetRoom);
    }

    #[DataProvider('provideEveryRoute')]
    public function test_guest_gets_401_on_room_routes(string $method, string $uriPattern): void
    {
        $targetRoom = $this->createRoom(self::ROOM_101);

        $this->json($method, strtr($uriPattern, ['{room}' => $targetRoom->id]), $this->buildRoomPayload(['name' => 'CHANGED']))
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertUnchanged($targetRoom);
    }

    private function assertUnchanged(Room $targetRoom): void
    {
        $reloadedRoom = $targetRoom->fresh();

        $this->assertSame(self::ROOM_101, $reloadedRoom->name);
        $this->assertTrue($reloadedRoom->is_active);
        $this->assertDatabaseCount('rooms', 1);
    }
}
