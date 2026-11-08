<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    // 08-prefixed so a factory contact never equals a fixed 09-prefixed test contact
    private const CONTACT_PATTERN = '08#########';

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->roles()->attach(Role::firstOrCreate(['name' => Role::ADMIN]));
        });
    }

    public function registrar(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->roles()->attach(Role::firstOrCreate(['name' => Role::REGISTRAR]));
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function withContact(): static
    {
        return $this->state(fn (array $attributes) => ['contact' => fake()->numerify(self::CONTACT_PATTERN)]);
    }
}
