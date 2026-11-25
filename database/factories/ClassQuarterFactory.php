<?php

namespace Database\Factories;

use App\Models\ClassQuarter;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassQuarter>
 */
class ClassQuarterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_id' => SchoolClass::factory(),
            'quarter' => 1,
            'status' => ClassQuarter::STATUS_DRAFT,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClassQuarter::STATUS_SUBMITTED,
            'submitted_by' => User::factory()->teacher(),
            'submitted_at' => now(),
        ]);
    }
}
