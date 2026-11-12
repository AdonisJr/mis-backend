<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    // default dates come from the parent year, so a default term always lies inside it; tests under fixed years pass explicit dates
    private const DEFAULT_TERM_DAYS = 120;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'term_number' => 1,
            'start_date' => fn (array $attributes) => SchoolYear::findOrFail($attributes['school_year_id'])->start_date->toDateString(),
            'end_date' => fn (array $attributes) => SchoolYear::findOrFail($attributes['school_year_id'])->start_date->addDays(self::DEFAULT_TERM_DAYS)->toDateString(),
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
