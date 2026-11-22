<?php

namespace Database\Factories;

use App\Models\GradingActivity;
use App\Models\GradingScore;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradingScore>
 */
class GradingScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grading_activity_id' => GradingActivity::factory(),
            'student_id' => Student::factory(),
            'score' => '10.0',
        ];
    }
}
