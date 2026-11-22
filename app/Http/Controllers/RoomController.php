<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SearchesPaginatedLists;
use App\Http\Requests\ListProgramsRequest;
use App\Http\Requests\ListRoomsRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class RoomController extends Controller
{
    use SearchesPaginatedLists;

    private const SEARCH_COLUMNS = ['name', 'building'];

    public function listRooms(ListRoomsRequest $request): JsonResponse
    {
        $roomQuery = Room::query()->orderBy('name')->orderBy('id');
        $this->applySearch($roomQuery, (string) $request->validated('search'));
        $roomQuery->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->validated('type')));
        $roomQuery->when($request->filled('status'), fn (Builder $query) => $query->where('is_active', $request->validated('status') === ListProgramsRequest::STATUS_ACTIVE));
        $roomPage = $roomQuery->paginate();

        return response()->json([
            'rooms' => RoomResource::collection($roomPage->getCollection()),
            'meta' => $this->buildPageMeta($roomPage),
        ]);
    }

    public function listActiveRooms(): JsonResponse
    {
        $activeRooms = Room::active()->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'rooms' => RoomResource::collection($activeRooms),
        ]);
    }

    public function createRoom(StoreRoomRequest $request): JsonResponse
    {
        $room = Room::create($request->validated());

        return response()->json([
            'room' => RoomResource::make($room),
        ], Response::HTTP_CREATED);
    }

    public function updateRoom(UpdateRoomRequest $request, int $id): JsonResponse
    {
        $room = Room::findOrFail($id);
        $room->update($request->validated());

        return response()->json([
            'room' => RoomResource::make($room),
        ]);
    }

    public function deactivateRoom(int $id): JsonResponse
    {
        return $this->saveActiveState($id, false);
    }

    public function reactivateRoom(int $id): JsonResponse
    {
        return $this->saveActiveState($id, true);
    }

    protected function getSearchColumns(): array
    {
        return self::SEARCH_COLUMNS;
    }

    private function saveActiveState(int $id, bool $isActive): JsonResponse
    {
        $room = Room::findOrFail($id);
        $room->forceFill(['is_active' => $isActive])->save();

        return response()->json([
            'room' => RoomResource::make($room),
        ]);
    }
}
