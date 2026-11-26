<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\PostingMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostingMapping>
 */
class PostingMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purpose' => PostingMapping::PURPOSE_AR_STUDENTS,
            'account_id' => Account::factory(),
        ];
    }
}
