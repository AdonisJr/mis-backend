<?php

namespace Database\Factories;

use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    // SX- names never equal or contain a fixed test name or search word
    private const NAME_PATTERN = 'SX-####';

    private const DEFAULT_CAPACITY = 40;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'grade_level_id' => fn (array $attributes) => $this->findFirstGradeLevelId(GradeLevel::STAGE_SHS),
            'program_id' => Program::factory()->shs(),
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'capacity' => self::DEFAULT_CAPACITY,
            'room' => null,
        ];
    }

    public function jhs(): static
    {
        return $this->state(fn (array $attributes) => ['grade_level_id' => $this->findFirstGradeLevelId(GradeLevel::STAGE_JHS), 'program_id' => null]);
    }

    public function college(): static
    {
        return $this->state(fn (array $attributes) => ['grade_level_id' => $this->findFirstGradeLevelId(GradeLevel::STAGE_COLLEGE), 'program_id' => Program::factory()->college()]);
    }

    private function findFirstGradeLevelId(string $stage): int
    {
        return GradeLevel::where('stage', $stage)->orderBy('sort_order')->value('id');
    }
}
