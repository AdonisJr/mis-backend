<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalLine>
 */
class JournalLineFactory extends Factory
{
    private const DEFAULT_AMOUNT = '100.00';

    private const ZERO_AMOUNT = '0.00';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'debit' => self::DEFAULT_AMOUNT,
            'credit' => self::ZERO_AMOUNT,
            'memo' => null,
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (array $attributes) => ['debit' => self::ZERO_AMOUNT, 'credit' => self::DEFAULT_AMOUNT]);
    }
}
