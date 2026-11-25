<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    // 7 digits, so a code never equals a 4-digit seeded code or a fixed 4-6 digit test code
    private const CODE_PATTERN = '9######';

    // never equals a seeded name and never contains a test search word (cash, bank, receivable)
    private const NAME_PATTERN = 'Zeta Ledger ####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify(self::CODE_PATTERN),
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'type' => Account::TYPE_ASSET,
            'subtype' => Account::SUBTYPE_CURRENT_ASSET,
            'is_contra' => false,
            'description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function contra(): static
    {
        return $this->state(fn (array $attributes) => ['is_contra' => true]);
    }
}
