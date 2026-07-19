<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserIp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserIp>
 */
class UserIpFactory extends Factory
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
            'ip' => fake()->ipv4(),
            'is_vpn' => null,
            'is_datacenter' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function vpn(): static
    {
        return $this->state(fn (): array => ['is_vpn' => true]);
    }

    public function datacenter(): static
    {
        return $this->state(fn (): array => ['is_datacenter' => true]);
    }
}
