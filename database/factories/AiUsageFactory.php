<?php

namespace Database\Factories;

use App\Models\AiUsage;
use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $promptTokens = fake()->numberBetween(100, 4000);
        $outputTokens = fake()->numberBetween(50, 2000);

        return [
            'book_id' => Book::factory(),
            'kind' => fake()->randomElement(['text', 'image', 'vision']),
            'provider' => fake()->randomElement(['openai', 'gemini', 'openrouter']),
            'model' => fake()->randomElement(['gpt-image-1', 'gemini-2.5-flash', 'gpt-4o-mini']),
            'prompt_tokens' => $promptTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $promptTokens + $outputTokens,
            'cost_usd' => fake()->randomFloat(6, 0.0001, 0.5),
            'estimated' => true,
        ];
    }
}
