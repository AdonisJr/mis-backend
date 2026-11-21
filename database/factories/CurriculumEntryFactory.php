<?php

namespace Database\Factories;

use App\Models\CurriculumEntry;
use App\Models\GradeLevel;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumEntry>
 */
class CurriculumEntryFactory extends Factory
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
            'subject_id' => Subject::factory(),
            'program_id' => null,
            'term_number' => null,
            'sort_order' => null,
        ];
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => [
            'grade_level_id' => $this->findFirstGradeLevelId(GradeLevel::STAGE_SHS),
            'subject_id' => Subject::factory()->shsCore(),
            'program_id' => Program::factory()->shs(),
            'term_number' => 1,
        ]);
    }

    private function findFirstGradeLevelId(string $stage): int
    {
        return GradeLevel::where('stage', $stage)->orderBy('sort_order')->value('id');
    }
}
