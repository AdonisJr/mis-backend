<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\GradeLevel;
use App\Models\PaymentPlan;
use App\Models\SchoolYear;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assessment>
 */
class AssessmentFactory extends Factory
{
    // AP- + digits never equals or contains a fixed test name or search word
    private const PLAN_NAME_PATTERN = 'AP-####';

    private const ZERO_AMOUNT = '0.00';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'school_year_id' => SchoolYear::factory(),
            'grade_level_id' => GradeLevel::where('stage', GradeLevel::STAGE_JHS)->orderBy('sort_order')->value('id'),
            'program_id' => null,
            'payment_plan_id' => PaymentPlan::factory(),
            'status' => Assessment::STATUS_CURRENT,
            'plan_name' => fake()->unique()->numerify(self::PLAN_NAME_PATTERN),
            'adjustment_kind' => null,
            'adjustment_type' => null,
            'adjustment_value' => null,
            'adjustment_amount' => self::ZERO_AMOUNT,
            'gross_tuition' => self::ZERO_AMOUNT,
            'gross_miscellaneous' => self::ZERO_AMOUNT,
            'gross_other' => self::ZERO_AMOUNT,
            'gross_total' => self::ZERO_AMOUNT,
            'discount_tuition' => self::ZERO_AMOUNT,
            'discount_miscellaneous' => self::ZERO_AMOUNT,
            'discount_other' => self::ZERO_AMOUNT,
            'discount_total' => self::ZERO_AMOUNT,
            'net_total' => self::ZERO_AMOUNT,
            'created_by' => null,
        ];
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Assessment::STATUS_SUPERSEDED,
            'superseded_at' => now(),
        ]);
    }
}
