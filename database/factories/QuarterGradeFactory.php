<?php

namespace Database\Factories;

use App\Models\ClassQuarter;
use App\Models\QuarterGrade;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuarterGrade>
 */
class QuarterGradeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_quarter_id' => ClassQuarter::factory()->submitted(),
            'student_id' => Student::factory(),
            'ww_percentage' => '35.00',
            'ww_weighted' => '10.50',
            'pt_percentage' => '50.00',
            'pt_weighted' => '25.00',
            'qa_percentage' => '0.00',
            'qa_weighted' => '0.00',
            'initial_grade' => '35.50',
            'quarterly_grade' => 80,
            'ww_weight' => 30,
            'pt_weight' => 50,
            'qa_weight' => 20,
        ];
    }
}
