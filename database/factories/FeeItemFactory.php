<?php

namespace Database\Factories;

use App\Models\FeeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeItem>
 */
class FeeItemFactory extends Factory
{
    // FX- names never equal or contain a fixed test name or search word
    private const NAME_PATTERN = 'FX-####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'category' => fake()->randomElement(FeeItem::CATEGORIES),
            'description' => null,
        ];
    }

    public function tuition(): static
    {
        return $this->state(fn (array $attributes) => ['category' => FeeItem::CATEGORY_TUITION]);
    }

    public function miscellaneous(): static
    {
        return $this->state(fn (array $attributes) => ['category' => FeeItem::CATEGORY_MISCELLANEOUS]);
    }

    public function other(): static
    {
        return $this->state(fn (array $attributes) => ['category' => FeeItem::CATEGORY_OTHER]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
