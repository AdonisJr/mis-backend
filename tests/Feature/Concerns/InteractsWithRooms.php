<?php

namespace Tests\Feature\Concerns;

use App\Models\GradeLevel;
use App\Models\Room;
use App\Models\Section;
use App\Models\User;

trait InteractsWithRooms
{
    use InteractsWithFees;

    private const ROOMS_URL = '/api/mis/rooms';

    private const ACTIVE_ROOMS_URL = '/api/mis/rooms/active';

    private const TEACHERS_URL = '/api/mis/teachers';

    private const ACTIVE_TEACHERS_URL = '/api/mis/teachers/active';

    private const SECTIONS_URL = '/api/mis/sections';

    private const ROOM_KEYS = ['id', 'name', 'building', 'capacity', 'type', 'is_active', 'created_at', 'updated_at'];

    private const TEACHER_KEYS = ['id', 'name', 'email', 'contact'];

    private const ROOM_101 = 'Rm 101';

    private const LAB_A = 'Lab A';

    private const MAIN_BUILDING = 'Main Building';

    private const SECTION_CAPACITY = 40;

    private function createCashierToken(): string
    {
        return $this->createTokenFor(User::factory()->cashier()->create(['email' => 'cashier@school.test']));
    }

    private function createTeacherToken(): string
    {
        return $this->createTokenFor(User::factory()->teacher()->create(['email' => 'teacher@school.test']));
    }

    private function createRoleLessToken(): string
    {
        return $this->createTokenFor(User::factory()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRoom(string $name, array $overrides = []): Room
    {
        return Room::factory()->create(['name' => $name, ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTeacher(array $overrides = []): User
    {
        return User::factory()->teacher()->create($overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildRoomPayload(array $overrides = []): array
    {
        return [
            'name' => self::ROOM_101,
            'building' => self::MAIN_BUILDING,
            'capacity' => 40,
            'type' => Room::TYPE_CLASSROOM,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     * @return array<string, mixed>
     */
    private function buildSectionPayload(string $gradeLevelName, array $sectionFields): array
    {
        return [
            'school_year_id' => $this->schoolYear->id,
            'grade_level_id' => GradeLevel::where('name', $gradeLevelName)->value('id'),
            'program_id' => null,
            'capacity' => self::SECTION_CAPACITY,
            'room_id' => null,
            'adviser_id' => null,
            ...$sectionFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionFields
     */
    private function createSection(array $sectionFields): Section
    {
        return Section::factory()->jhs()->create([
            'school_year_id' => $this->schoolYear->id,
            ...$sectionFields,
        ]);
    }
}
