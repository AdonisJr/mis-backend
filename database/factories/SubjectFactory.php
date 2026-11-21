<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    // SX- codes and names never equal or contain a fixed test code, name or search word
    private const CODE_PATTERN = 'SX-####';

    private const NAME_PATTERN = 'SX Name ####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(self::CODE_PATTERN),
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'type' => Subject::TYPE_JHS_LEARNING_AREA,
            'weekly_hours' => '4.0',
            'description' => null,
        ];
    }

    public function shsCore(): static
    {
        return $this->state(fn (array $attributes) => ['type' => Subject::TYPE_SHS_CORE]);
    }

    public function shsApplied(): static
    {
        return $this->state(fn (array $attributes) => ['type' => Subject::TYPE_SHS_APPLIED]);
    }

    public function shsSpecialized(): static
    {
        return $this->state(fn (array $attributes) => ['type' => Subject::TYPE_SHS_SPECIALIZED]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
