<?php

namespace Database\Factories;

use App\Models\ClassMeeting;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassMeeting>
 */
class ClassMeetingFactory extends Factory
{
    // 07:00 default never equals a fixed test slot (tests use 08:00 onward)
    private const DEFAULT_START_MINUTES = 420;

    private const DEFAULT_END_MINUTES = 480;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'day' => ClassMeeting::DAY_MONDAY,
            'start_minutes' => self::DEFAULT_START_MINUTES,
            'end_minutes' => self::DEFAULT_END_MINUTES,
            'room_id' => null,
        ];
    }
}
