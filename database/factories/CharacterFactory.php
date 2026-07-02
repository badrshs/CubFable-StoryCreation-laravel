<?php

namespace Database\Factories;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Character>
 */
class CharacterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->firstName(),
            'role' => fake()->randomElement(['self', 'dad', 'mom', 'sibling', 'friend']),
            'description' => fake()->sentence(),
            'photo_path' => null,
            'appearance' => null,
        ];
    }

    /**
     * A character with a reference photo on disk.
     */
    public function withPhoto(): static
    {
        return $this->state(fn (array $attributes) => [
            'photo_path' => 'characters/'.fake()->numberBetween(1, 9999).'/photo-'.Str::lower(Str::random(8)).'.jpg',
        ]);
    }
}
