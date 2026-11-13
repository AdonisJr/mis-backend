<?php

namespace Database\Factories;

use App\Models\FeeSchedule;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeSchedule>
 */
class FeeScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'grade_level_id' => fn (array $attributes) => $this->findFirstGradeLevelId(GradeLevel::STAGE_JHS),
            'program_id' => null,
        ];
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => ['grade_level_id' => $this->findFirstGradeLevelId(GradeLevel::STAGE_SHS), 'program_id' => null]);
    }

    public function withProgram(): static
    {
        return $this->state(fn (array $attributes) => ['grade_level_id' => $this->findFirstGradeLevelId(GradeLevel::STAGE_SHS), 'program_id' => Program::factory()->shs()]);
    }

    private function findFirstGradeLevelId(string $stage): int
    {
        return GradeLevel::where('stage', $stage)->orderBy('sort_order')->value('id');
    }
}
