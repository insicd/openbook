<?php

namespace Database\Factories;

use App\Domain\Accounts\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => Str::of(fake()->unique()->userName())
                ->lower()
                ->replaceMatches('/[^a-z0-9_]/', '')
                ->substr(0, 32)
                ->toString(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'is_admin' => false,
            'is_moderator' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'is_moderator' => true,
        ]);
    }

    public function moderator(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_moderator' => true,
        ]);
    }
}
