<?php

namespace Database\Factories;

use App\Models\CurriculumEntry;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory()->jhs(),
            'curriculum_entry_id' => fn (array $attributes) => $this->createEntryForSection(Section::findOrFail($attributes['section_id'])),
            'teacher_id' => User::factory()->teacher(),
            'school_year_id' => fn (array $attributes) => Section::findOrFail($attributes['section_id'])->school_year_id,
            'term_number' => fn (array $attributes) => CurriculumEntry::findOrFail($attributes['curriculum_entry_id'])->term_number,
        ];
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => [
            'section_id' => Section::factory(),
            'curriculum_entry_id' => fn (array $sectionAttributes) => CurriculumEntry::factory()->shs()->create($this->buildShsEntryFields(Section::findOrFail($sectionAttributes['section_id']))),
        ]);
    }

    private function createEntryForSection(Section $section): CurriculumEntry
    {
        return CurriculumEntry::factory()->create([
            'school_year_id' => $section->school_year_id,
            'grade_level_id' => $section->grade_level_id,
        ]);
    }

    /** @return array<string, int> */
    private function buildShsEntryFields(Section $section): array
    {
        return [
            'school_year_id' => $section->school_year_id,
            'grade_level_id' => $section->grade_level_id,
            'program_id' => $section->program_id,
            'term_number' => 1,
        ];
    }
}
