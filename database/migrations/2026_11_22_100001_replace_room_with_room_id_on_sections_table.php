<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROOM_NAME_MAX_LENGTH = 50;

    private const MIGRATED_ROOM_TYPE = 'classroom';

    private const OVERLONG_ROOM_MESSAGE = 'Shorten these section rooms to 50 characters before migrating: %s';

    /** @var array<string, int> */
    private array $roomIdsByKey = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->abortOnOverlongRoomTexts();

        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained('rooms')->restrictOnDelete();
        });

        $this->moveRoomTextsToRooms();

        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('room')->nullable();
        });

        DB::table('sections')->whereNotNull('room_id')->update(['room' => DB::raw('(SELECT rooms.name FROM rooms WHERE rooms.id = sections.room_id)')]);

        // rooms are kept: they may have been edited or linked since, so deleting them could lose data
        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }

    private function abortOnOverlongRoomTexts(): void
    {
        $overlongSections = DB::table('sections')
            ->whereRaw('LENGTH(TRIM(room)) > ?', [self::ROOM_NAME_MAX_LENGTH])
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($overlongSections->isEmpty()) {
            return;
        }

        $sectionLabels = $overlongSections->map(fn ($section) => '#'.$section->id.' '.$section->name)->implode(', ');

        throw new RuntimeException(sprintf(self::OVERLONG_ROOM_MESSAGE, $sectionLabels));
    }

    private function moveRoomTextsToRooms(): void
    {
        $roomSections = DB::table('sections')->whereNotNull('room')->orderBy('id')->get(['id', 'room']);

        foreach ($roomSections as $roomSection) {
            if (trim($roomSection->room) === '') {
                continue;
            }

            DB::table('sections')->where('id', $roomSection->id)->update(['room_id' => $this->findOrCreateRoomId(trim($roomSection->room))]);
        }
    }

    private function findOrCreateRoomId(string $roomName): int
    {
        $roomKey = mb_strtolower($roomName);

        if (isset($this->roomIdsByKey[$roomKey])) {
            return $this->roomIdsByKey[$roomKey];
        }

        // down() keeps the rooms, so a re-run after a rollback must link to them, not duplicate them
        $existingRoomId = DB::table('rooms')->whereRaw('LOWER(name) = ?', [$roomKey])->orderBy('id')->value('id');

        // sections are read in id order, so the lowest section id decides the spelling of a merged room
        $this->roomIdsByKey[$roomKey] = $existingRoomId ?? DB::table('rooms')->insertGetId([
            'name' => $roomName,
            'building' => null,
            'capacity' => null,
            'type' => self::MIGRATED_ROOM_TYPE,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->roomIdsByKey[$roomKey];
    }
};
