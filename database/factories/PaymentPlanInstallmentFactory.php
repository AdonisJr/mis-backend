<?php

namespace Database\Factories;

use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlanInstallment>
 */
class PaymentPlanInstallmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_plan_id' => PaymentPlan::factory(),
            'sequence' => 1,
            'due_date' => fn (array $attributes) => PaymentPlan::find($attributes['payment_plan_id'])->schoolYear->start_date->toDateString(),
        ];
    }
}
