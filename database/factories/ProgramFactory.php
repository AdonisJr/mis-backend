<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    // ZX codes and tree-named tracks never equal or contain a fixed test code, seeded code or test search word
    private const CODE_PATTERN = 'ZX####';

    private const NAMES = ['Molave Track', 'Narra Track', 'Yakal Track'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(self::CODE_PATTERN),
            'name' => fake()->randomElement(self::NAMES),
            'stage' => fake()->randomElement(GradeLevel::PROGRAM_STAGES),
            'description' => null,
        ];
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => ['stage' => GradeLevel::STAGE_SHS]);
    }

    public function college(): static
    {
        return $this->state(fn (array $attributes) => ['stage' => GradeLevel::STAGE_COLLEGE]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
