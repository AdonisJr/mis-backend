<?php

namespace Database\Factories;

use App\Models\ClassQuarter;
use App\Models\ClassQuarterEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassQuarterEvent>
 */
class ClassQuarterEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_quarter_id' => ClassQuarter::factory(),
            'action' => ClassQuarterEvent::ACTION_SUBMITTED,
            'reason' => null,
            'user_id' => User::factory()->teacher(),
        ];
    }
}
