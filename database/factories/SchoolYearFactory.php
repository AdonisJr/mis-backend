<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolYear>
 */
class SchoolYearFactory extends Factory
{
    // Kept above the fixed names tests use (e.g. "2026-2027") so the unique name never collides.
    private const FIRST_START_YEAR = 2100;

    private const LAST_START_YEAR = 2999;

    private const START_MONTH_DAY = '-08-01';

    private const END_MONTH_DAY = '-05-31';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(self::FIRST_START_YEAR, self::LAST_START_YEAR);
        $endYear = $startYear + 1;

        return [
            'name' => "{$startYear}-{$endYear}",
            'start_date' => $startYear.self::START_MONTH_DAY,
            'end_date' => $endYear.self::END_MONTH_DAY,
            'description' => null,
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
