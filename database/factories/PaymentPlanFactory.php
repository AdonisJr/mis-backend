<?php

namespace Database\Factories;

use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    // PX- names never equal or contain a fixed test name or search word
    private const NAME_PATTERN = 'PX-####';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'name' => fake()->unique()->numerify(self::NAME_PATTERN),
            'description' => null,
            'down_payment_type' => null,
            'down_payment_value' => null,
            'adjustment_kind' => null,
            'adjustment_type' => null,
            'adjustment_value' => null,
        ];
    }

    public function withInstallments(int $installmentCount = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'down_payment_type' => PaymentPlan::TYPE_FIXED,
            'down_payment_value' => '1000.00',
        ])->afterCreating(function (PaymentPlan $paymentPlan) use ($installmentCount) {
            $startDate = $paymentPlan->schoolYear->start_date->toImmutable();

            // up to nine monthly dates stay inside every factory and fixture school year
            foreach (range(1, $installmentCount) as $sequence) {
                $paymentPlan->installments()->create(['sequence' => $sequence, 'due_date' => $startDate->addMonthsNoOverflow($sequence)->toDateString()]);
            }
        });
    }

    public function percentDownPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'down_payment_type' => PaymentPlan::TYPE_PERCENT,
            'down_payment_value' => '20.00',
        ]);
    }

    public function discount(): static
    {
        return $this->state(fn (array $attributes) => [
            'adjustment_kind' => PaymentPlan::KIND_DISCOUNT,
            'adjustment_type' => PaymentPlan::TYPE_PERCENT,
            'adjustment_value' => '5.00',
        ]);
    }

    public function surcharge(): static
    {
        return $this->state(fn (array $attributes) => [
            'adjustment_kind' => PaymentPlan::KIND_SURCHARGE,
            'adjustment_type' => PaymentPlan::TYPE_FIXED,
            'adjustment_value' => '500.00',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
