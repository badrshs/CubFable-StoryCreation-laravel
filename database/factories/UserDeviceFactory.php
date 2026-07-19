<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserDevice>
 */
class UserDeviceFactory extends Factory
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
            'device_id' => (string) Str::uuid(),
            'fingerprint' => fake()->sha256(),
            'user_agent' => fake()->userAgent(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
