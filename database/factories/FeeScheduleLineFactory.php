<?php

namespace Database\Factories;

use App\Models\FeeItem;
use App\Models\FeeSchedule;
use App\Models\FeeScheduleLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeScheduleLine>
 */
class FeeScheduleLineFactory extends Factory
{
    private const DEFAULT_AMOUNT = '1000.00';

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_schedule_id' => FeeSchedule::factory(),
            'fee_item_id' => FeeItem::factory(),
            'amount' => self::DEFAULT_AMOUNT,
        ];
    }
}
