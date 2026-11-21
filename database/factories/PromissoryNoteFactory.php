<?php

namespace Database\Factories;

use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromissoryNote>
 */
class PromissoryNoteFactory extends Factory
{
    // never equals a fixed amount or reason used by the tests
    private const DEFAULT_AMOUNT = '123.45';

    private const DEFAULT_CANCEL_REASON = 'Factory cancel';

    private const ACTIVE_DAYS_AHEAD = 7;

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
            'promised_amount' => self::DEFAULT_AMOUNT,
            'promise_date' => fn () => today()->addDays(self::ACTIVE_DAYS_AHEAD)->toDateString(),
            'remarks' => null,
            'granted_by' => User::factory(),
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ];
    }

    public function pastDate(): static
    {
        return $this->state(fn (array $attributes) => ['promise_date' => today()->subDay()->toDateString()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'cancelled_at' => now(),
            'cancelled_by' => User::factory(),
            'cancel_reason' => self::DEFAULT_CANCEL_REASON,
        ]);
    }
}
