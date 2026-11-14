<?php

namespace Database\Factories;

use App\Models\DiscountType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentDiscount>
 */
class StudentDiscountFactory extends Factory
{
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
            'discount_type_id' => DiscountType::factory(),
            'reference_no' => null,
            'remarks' => null,
        ];
    }
}
