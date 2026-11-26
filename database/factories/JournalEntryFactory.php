<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    // never contains a test search word (rent, utilities, INV)
    private const DESCRIPTION_PATTERN = 'Zeta voucher ####';

    // never equals a counter-issued number (JV-000001 onwards)
    private const POSTED_NUMBER_MIN = 900000;

    private const POSTED_NUMBER_MAX = 999999;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // 2020 dates: tests use 2026 dates, so a factory entry never lands in a test's range
            'entry_date' => fake()->dateTimeBetween('2020-01-01', '2020-12-31')->format('Y-m-d'),
            'description' => fake()->numerify(self::DESCRIPTION_PATTERN),
            'reference' => null,
            'status' => JournalEntry::STATUS_DRAFT,
            'source' => JournalEntry::SOURCE_MANUAL,
            'created_by' => User::factory(),
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JournalEntry::STATUS_POSTED,
            'number' => sprintf(JournalEntry::NUMBER_FORMAT, fake()->unique()->numberBetween(self::POSTED_NUMBER_MIN, self::POSTED_NUMBER_MAX)),
            'posted_by' => User::factory(),
            'posted_at' => now(),
        ]);
    }

    public function reversalOf(JournalEntry $original): static
    {
        return $this->posted()->state(fn (array $attributes) => ['reversal_of_id' => $original->id]);
    }
}
