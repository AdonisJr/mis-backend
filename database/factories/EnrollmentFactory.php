<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    /**
     * Define the model's default state (a junior high school enrollment: whole year, no term).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'section_id' => Section::factory()->jhs(),
            'term_id' => null,
            'status' => Enrollment::STATUS_ENROLLED,
            'enrolled_at' => fn (array $attributes) => Section::findOrFail($attributes['section_id'])->schoolYear->start_date->toDateString(),
            'ended_at' => null,
            'remarks' => null,
        ];
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => [
            'section_id' => Section::factory(),
            'term_id' => fn (array $sectionAttributes) => $this->createTermForSection($sectionAttributes['section_id']),
        ]);
    }

    public function college(): static
    {
        return $this->state(fn (array $attributes) => [
            'section_id' => Section::factory()->college(),
            'term_id' => fn (array $sectionAttributes) => $this->createTermForSection($sectionAttributes['section_id']),
        ]);
    }

    public function closed(string $closingStatus): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $closingStatus,
            'ended_at' => $attributes['enrolled_at'],
        ]);
    }

    private function createTermForSection(int $sectionId): int
    {
        return Term::factory()->create(['school_year_id' => Section::findOrFail($sectionId)->school_year_id])->id;
    }
}
