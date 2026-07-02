<?php

namespace Database\Factories;

use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ageMin = fake()->numberBetween(2, 5);

        return [
            'title' => fake()->unique()->words(3, true),
            'description' => fake()->paragraph(),
            'theme' => fake()->randomElement(['forest', 'pirates', 'space', 'kitchen', 'dinosaurs', 'rainbow']),
            'age_min' => $ageMin,
            'age_max' => $ageMin + fake()->numberBetween(3, 5),
            'cover_image_url' => 'data:image/svg+xml;base64,'.base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>'
            ),
            'page_count' => fake()->numberBetween(5, 8),
            'life_lessons' => fake()->randomElements(['Kindness', 'Courage', 'Friendship', 'Patience', 'Honesty', 'Teamwork'], 3),
            'art_styles' => fake()->randomElements(array_column(ArtStyle::cases(), 'value'), 3),
            'subjects' => fake()->randomElements(['animals', 'nature', 'adventure', 'ocean', 'magic', 'family'], 3),
            'fonts' => fake()->randomElements(array_column(FontChoice::cases(), 'value'), 3),
            'image_prompt' => fake()->paragraph(),
        ];
    }
}
