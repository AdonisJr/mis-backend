<?php

namespace Database\Factories;

use App\Models\DiscountType;
use App\Models\GradeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscountType>
 */
class DiscountTypeFactory extends Factory
{
    // DX- names never equal or contain a fixed test name or search word
    private const NAME_PATTERN = 'DX-####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'description' => null,
            'source' => DiscountType::SOURCE_SCHOOL,
            'value_type' => DiscountType::VALUE_TYPE_FIXED,
            'value' => '1000.00',
            'base' => DiscountType::BASE_TUITION,
            'stages' => [GradeLevel::STAGE_JHS],
        ];
    }

    public function government(): static
    {
        return $this->state(fn (array $attributes) => ['source' => DiscountType::SOURCE_GOVERNMENT]);
    }

    public function percent(): static
    {
        return $this->state(fn (array $attributes) => ['value_type' => DiscountType::VALUE_TYPE_PERCENT, 'value' => '10.00']);
    }

    public function totalBase(): static
    {
        return $this->state(fn (array $attributes) => ['base' => DiscountType::BASE_TOTAL]);
    }

    public function shs(): static
    {
        return $this->state(fn (array $attributes) => ['stages' => [GradeLevel::STAGE_SHS]]);
    }

    public function bothStages(): static
    {
        return $this->state(fn (array $attributes) => ['stages' => DiscountType::STAGES]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
