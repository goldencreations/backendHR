<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * The schema has no email_verified_at column: sign-in is by email and
     * password only, so the account state is carried by is_active.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_EMPLOYEE,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function hrAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_HR_ADMIN,
        ]);
    }

    public function hrOfficer(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_HR_OFFICER,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
