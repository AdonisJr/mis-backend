<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    // RX- names never equal or contain a fixed test room name or search word
    private const NAME_PATTERN = 'RX-####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'building' => null,
            'capacity' => null,
            'type' => Room::TYPE_CLASSROOM,
        ];
    }

    public function laboratory(): static
    {
        return $this->state(fn (array $attributes) => ['type' => Room::TYPE_LABORATORY]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
