<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\StudentAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentAttendance>
 */
class StudentAttendanceFactory extends Factory
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
            // 21xx dates never equal the 2030 dates the tests fix
            'date' => fake()->unique()->dateTimeBetween('2100-01-01', '2199-12-31')->format('Y-m-d'),
            'time_in' => '07:00:00',
            'time_out' => null,
            'status' => StudentAttendance::STATUS_PRESENT,
        ];
    }

    public function late(): static
    {
        return $this->state(fn () => ['time_in' => '07:45:00', 'status' => StudentAttendance::STATUS_LATE]);
    }

    public function timedOut(): static
    {
        return $this->state(fn () => ['time_out' => '16:00:00']);
    }
}
