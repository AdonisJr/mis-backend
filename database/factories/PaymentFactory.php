<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    // FX- never equals an AR- number from the allocator, nor a fixed search word used in tests
    private const AR_NUMBER_PATTERN = 'FX-######';

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
            'assessment_id' => Assessment::factory(),
            'student_id' => fn (array $attributes) => Assessment::find($attributes['assessment_id'])->student_id,
            'school_year_id' => fn (array $attributes) => Assessment::find($attributes['assessment_id'])->school_year_id,
            'ar_number' => fake()->unique()->numerify(self::AR_NUMBER_PATTERN),
            'amount' => self::DEFAULT_AMOUNT,
            'payment_date' => today()->toDateString(),
            'method' => Payment::METHOD_CASH,
            'reference_no' => null,
            'remarks' => null,
            'balance_after' => self::ZERO_AMOUNT,
            'recorded_by' => User::factory(),
        ];
    }

    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'voided_at' => now(),
            'voided_by' => User::factory(),
            'void_reason' => 'Factory void',
        ]);
    }
}
