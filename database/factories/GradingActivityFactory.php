<?php

namespace Database\Factories;

use App\Models\GradingActivity;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingActivity>
 */
class GradingActivityFactory extends Factory
{
    // GX titles never equal a fixed test title
    private const TITLE_PATTERN = 'GX Activity ####';

    private const HIGHEST_SCORE = 20;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'quarter' => 1,
            'component' => GradingActivity::COMPONENT_WW,
            'title' => fake()->unique()->numerify(self::TITLE_PATTERN),
            'highest_possible_score' => self::HIGHEST_SCORE,
            'activity_date' => null,
        ];
    }

    public function performanceTask(): static
    {
        return $this->state(fn (array $attributes) => ['component' => GradingActivity::COMPONENT_PT]);
    }

    public function quarterlyAssessment(): static
    {
        return $this->state(fn (array $attributes) => ['component' => GradingActivity::COMPONENT_QA]);
    }
}
