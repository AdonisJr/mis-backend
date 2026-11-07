<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    // Kept clear of the fixed LRNs, names and sections tests use, so unique and search assertions never collide.
    private const LRN_PATTERN = '9###########';

    private const RFID_PATTERN = '([0-9A-F]{2}:){6}[0-9A-F]{2}';

    private const FIRST_NAMES = ['Ligaya', 'Dakila', 'Tala', 'Bayani'];

    private const LAST_NAMES = ['Magsaysay', 'Quezon', 'Osmena', 'Laurel'];

    private const SECTIONS = ['Sampaguita', 'Narra', 'Molave'];

    private const GUARDIAN_RELATIONSHIPS = ['Mother', 'Father', 'Guardian'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lrn' => fake()->unique()->numerify(self::LRN_PATTERN),
            'first_name' => fake()->randomElement(self::FIRST_NAMES),
            'middle_name' => fake()->randomElement(self::LAST_NAMES),
            'last_name' => fake()->randomElement(self::LAST_NAMES),
            'birth_date' => fake()->dateTimeBetween('-25 years', '-12 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Student::GENDERS),
            'grade_level' => Arr::first(Student::GRADE_LEVELS),
            'section' => fake()->randomElement(self::SECTIONS),
            'address' => null,
            'contact' => null,
            'guardian_name' => fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES),
            'guardian_relationship' => fake()->randomElement(self::GUARDIAN_RELATIONSHIPS),
            'guardian_contact' => fake()->numerify('09#########'),
            'rfid' => null,
            'user_id' => null,
        ];
    }

    public function withRfid(): static
    {
        return $this->state(fn (array $attributes) => [
            'rfid' => fake()->unique()->regexify(self::RFID_PATTERN),
        ]);
    }
}
