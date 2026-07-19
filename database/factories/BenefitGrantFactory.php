<?php

namespace Database\Factories;

use App\Models\BenefitGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BenefitGrant>
 */
class BenefitGrantFactory extends Factory
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
            'benefit' => 'demo',
            'device_id' => (string) Str::uuid(),
            'fingerprint' => fake()->sha256(),
            'ip' => fake()->ipv4(),
        ];
    }
}
